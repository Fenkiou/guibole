<?php

declare(strict_types=1);

namespace Bga\Games\guibole;

use Bga\Games\guibole\States\StartRound;

class Game extends \Bga\GameFramework\Table
{
    const DECK = 'deck';
    const HAND = 'hand';
    const DISCARD = 'discard';
    const TMP_DISCARD = 'tmp_discard';
    const PLAYED_CARDS = 'played_cards';
    const DECK_COUNT = 'deck_count';

    public static Game $instance;

    public $cards;

    public Material $material;

    public function __construct()
    {
        parent::__construct();

        self::$instance = $this;

        $this->initGameStateLabels([
            'startingPlayerId' => 10,
            'gameLengthOption' => 100,
        ]);

        $this->material = new Material();
        $this->cards = $this->bga->deckFactory->createDeck('card');
    }

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $starting_score = $this->getGameStateValue('gameLengthOption') == 1 ? 100 : 500;

        $sql = 'INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ';
        $values = [];
        foreach ($players as $player_id => $player) {
            $color = array_shift($default_colors);
            $values[] = "('{$player_id}','{$color}','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
        }
        $sql .= implode(',', $values);
        $this->DbQuery($sql);

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        // Set starting score using the framework's score counter
        $this->bga->playerScore->initDb(array_keys($players), $starting_score);

        $cards = [];
        foreach ($this->material->getColors() as $color_id => $color) {
            for ($value = 13; $value >= 1; $value--) {
                $cards[] = ['type' => $color_id, 'type_arg' => $value, 'nbr' => 1];
            }
        }

        $this->cards->createCards($cards, self::DECK);

        $this->activeNextPlayer();
        $this->setGameStateInitialValue('startingPlayerId', (int)$this->getActivePlayerId());

        $this->initStat('table', 'rounds_count', 0);
        $this->initStat('player', 'ended_round_count', 0);
        $this->initStat('player', 'failed_round_count', 0);
        $this->initStat('player', 'counter_count', 0);

        return StartRound::class;
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];

        $result['players'] = $this->getPlayersData();
        $result[self::HAND] = $this->cards->getCardsInLocation(self::HAND, $currentPlayerId);
        $result[self::DISCARD] = $this->getDiscardedCards();
        $result[self::PLAYED_CARDS] = $this->getPlayedCards();
        $result[self::DECK_COUNT] = $this->cards->countCardsInLocation(self::DECK);
        $result['state_name'] = $this->gamestate->getCurrentState($currentPlayerId)->name;

        return $result;
    }

    public function getGameProgression()
    {
        $players = $this->getPlayersData();
        $game_length = $this->getGameStateValue('gameLengthOption') == 1 ? 100 : 500;
        $lowest_score = $game_length;

        foreach ($players as $player_id => $player) {
            if ($player['score'] < $lowest_score) {
                $lowest_score = $player['score'];
            }
        }

        if ($lowest_score == $game_length) {
            return 0;
        }

        return (int)(($game_length - $lowest_score) * 100 / $game_length);
    }

    public function doEndRound(int $current_player_id): void
    {
        $current_player_name = $this->getActivePlayerName();
        $current_player_hand_points = $this->getHandPointsForPlayerId($current_player_id);

        $players_with_points = [];
        $eliminating_hand = false;

        if ($current_player_hand_points > 10) {
            $players_with_points[$current_player_id] = 45;
            $eliminating_hand = true;
        }

        $players = $this->loadPlayersBasicInfos();

        if (!count($players_with_points)) {
            foreach ($players as $player_id => $player) {
                if ($player_id == $current_player_id) {
                    continue;
                }

                $player_hand_points = $this->getHandPointsForPlayerId($player_id);

                if ($player_hand_points <= $current_player_hand_points) {
                    $players_with_points[$current_player_id] = $current_player_hand_points * 2 + 25;
                } else {
                    $players_with_points[$player_id] = $player_hand_points;
                }
            }
        }

        if (isset($players_with_points[$current_player_id])) {
            $message = clienttranslate('${player_name} shows: ${card_values} and lose ${hand_point} points');
            $points = $players_with_points[$current_player_id];
            $this->incStat(1, 'failed_round_count', $current_player_id);
        } else {
            $message = clienttranslate('${player_name} shows: ${card_values} and do not lose point');
            $points = 0;
        }

        $this->bga->notify->all(
            'message',
            $message,
            [
                'player_name' => $current_player_name,
                'card_values' => implode(', ', array_map(
                    [$this, 'getCardHumanReadableValue'],
                    array_values($this->getPlayerCards($current_player_id))
                )),
                'hand_point' => $points,
            ]
        );

        foreach ($players as $player_id => $player) {
            if ($player_id == $current_player_id) {
                continue;
            }

            if (isset($players_with_points[$player_id])) {
                $message = clienttranslate('${player_name} have: ${card_values} and lose ${hand_point} points');
            } elseif ($eliminating_hand) {
                $message = clienttranslate('${player_name} have: ${card_values} and do not lose points');
            } else {
                $message = clienttranslate('${player_name} have: ${card_values} and counter ${current_player_name}');
                $this->incStat(1, 'counter_count', $player_id);
            }

            $this->bga->notify->all(
                'message',
                $message,
                [
                    'player_name' => $player['player_name'],
                    'card_values' => implode(', ', array_map(
                        [$this, 'getCardHumanReadableValue'],
                        array_values($this->getPlayerCards($player_id))
                    )),
                    'hand_point' => $this->getHandPointsForPlayerId($player_id),
                    'current_player_name' => $current_player_name,
                ]
            );
        }

        foreach ($players_with_points as $player_id => $player_points) {
            $this->updateScoreForPlayer($player_id, $player_points);
        }

        $this->notifyPlayersAboutScores(
            $this->cards->getCardsInLocation(self::HAND, $current_player_id),
            $current_player_id
        );

        $this->incStat(1, 'ended_round_count', $current_player_id);

        $this->setGameStateValue('startingPlayerId', $this->getPlayerAfter($this->getGameStateValue('startingPlayerId')));
    }

    public function upgradeTableDb($from_version)
    {
    }

    public function getPlayedCards(): array
    {
        return $this->cards->getCardsInLocation(self::PLAYED_CARDS);
    }

    public function setPlayedCards(array $cards): void
    {
        $this->cards->moveCards($this->getCardIds($cards), self::PLAYED_CARDS);

        $this->bga->notify->all('playedCards', '', [
            'cards' => $cards,
            'player_id' => (int)$this->getActivePlayerId(),
        ]);

        if (!count($cards)) {
            return;
        }

        $this->bga->notify->all(
            'message',
            clienttranslate('${player_name} plays: ${card_values}'),
            [
                'player_id' => (int)$this->getActivePlayerId(),
                'player_name' => $this->getActivePlayerName(),
                'card_values' => implode(', ', array_map(
                    [$this, 'getCardHumanReadableValue'],
                    array_values($cards)
                )),
            ]
        );
    }

    public function getDiscardedCards(): array
    {
        return $this->cards->getCardsInLocation(self::TMP_DISCARD);
    }

    public function setDiscardedCards(array $cards, bool $reset_played_cards): void
    {
        $this->cards->moveAllCardsInLocation(self::TMP_DISCARD, self::DISCARD);
        $this->cards->moveCards($this->getCardIds($cards), self::TMP_DISCARD);

        $from = $reset_played_cards ? self::PLAYED_CARDS : self::DECK;

        $this->bga->notify->all('discardedCards', '', [
            'cards' => $cards,
            'from' => $from,
        ]);
    }

    public function shuffleDeckIfNeeded(): void
    {
        if (!$this->cards->countCardsInLocation(self::DECK)) {
            $last_discarded_card = $this->cards->getCardOnTop(self::TMP_DISCARD);

            $this->cards->moveAllCardsInLocation(self::TMP_DISCARD, self::DECK);
            $this->cards->moveAllCardsInLocation(self::DISCARD, self::DECK);
            $this->cards->shuffle(self::DECK);

            $this->cards->moveCard($last_discarded_card['id'], self::TMP_DISCARD);

            $this->bga->notify->all(
                'message',
                clienttranslate('Shuffling discarded cards and refilling the deck'),
                []
            );
        }
    }

    public function getCards(array $card_ids): array
    {
        return $this->cards->getCards($card_ids);
    }

    public function getCardIds(array $cards): array
    {
        return array_column($cards, 'id');
    }

    public function currentUserTakeCard(?int $card_id): void
    {
        if ($this->getActivePlayerId() != $this->getCurrentPlayerId()) {
            throw new \Bga\GameFramework\UserException(clienttranslate('This is not your turn.'));
        }

        if (!$card_id) {
            $card = $this->cards->getCardOnTop(self::DECK);
        } else {
            $card = $this->cards->getCard($card_id);
        }

        if (!$card) {
            throw new \Bga\GameFramework\UserException(clienttranslate('This card does not exists'));
        }

        if ($card['location'] !== self::DECK && $card['location'] !== self::TMP_DISCARD) {
            throw new \Bga\GameFramework\UserException(clienttranslate('This card cannot be taken'));
        }

        $current_player_id = (int)$this->getActivePlayerId();
        $this->cards->moveCard($card['id'], self::HAND, $current_player_id);

        $message = $card['location'] === self::DECK
            ? clienttranslate('${player_name} takes a card from the deck')
            : clienttranslate('${player_name} takes a card from the discard');

        $this->shuffleDeckIfNeeded();

        $this->bga->notify->all('message', $message, [
            'player_name' => $this->getPlayerNameById($current_player_id),
        ]);

        $this->bga->notify->player($current_player_id, 'cardTaken', '', [
            'card' => $card,
            'from' => $card['location'],
            'to_player_id' => $current_player_id,
        ]);

        $this->bga->notify->all('cardTaken', '', [
            'card' => null,
            'from' => $card['location'],
            'to_player_id' => $current_player_id,
        ]);

        if (!$card_id) {
            $this->bga->notify->all('deckCountUpdate', '', [
                'deck_count' => $this->cards->countCardsInLocation(self::DECK),
            ]);
        }
    }

    public function notifyPlayerAboutHisHand(int $player_id): void
    {
        $this->bga->notify->player($player_id, 'newHand', '', [
            'cards' => $this->cards->getCardsInLocation(self::HAND, $player_id),
        ]);
    }

    public function getCardValue(array $card): int
    {
        $card_value = (int)$card['type_arg'];

        if ($card_value > 10) {
            $card_value = 10;
        }

        return $card_value;
    }

    public function getCardHumanReadableValue(array $card): string|int
    {
        return match((int)$card['type_arg']) {
            1 => 'A',
            11 => 'J',
            12 => 'Q',
            13 => 'K',
            default => $card['type_arg'],
        };
    }

    public function getPlayerCards(int $player_id): array
    {
        return $this->cards->getCardsInLocation(self::HAND, $player_id);
    }

    public function getPlayerCardsCount(int $player_id): int
    {
        return count($this->getPlayerCards($player_id));
    }

    public function getHandPointsForPlayerId(int $player_id): int
    {
        $cards = $this->getPlayerCards($player_id);
        $points = 0;

        foreach ($cards as $card) {
            $points += $this->getCardValue($card);
        }

        return $points;
    }

    public function getPlayerScore(int $player_id): int
    {
        return (int)$this->getUniqueValueFromDB('SELECT player_score FROM player WHERE player_id = ' . $player_id);
    }

    public function updateScoreForPlayer(int $player_id, int $points): int
    {
        return $this->bga->playerScore->inc($player_id, -$points);
    }

    public function getPlayersData(): array
    {
        $players = $this->getCollectionFromDb('SELECT player_id AS id, player_score AS score FROM player');
        foreach ($players as $player_id => &$player) {
            $player['cards_count'] = $this->getPlayerCardsCount((int)$player_id);
        }
        return $players;
    }

    public function notifyPlayersAboutScores(array $showedCards, int $current_player_id): void
    {
        $this->bga->notify->all('updateScore', '', [
            'players' => $this->getPlayersData(),
            'cards' => $showedCards,
            'current_player_id' => $current_player_id,
        ]);
    }

    public function notifyAllPlayersAboutCurrentPlayerCardsCount(): void
    {
        $player_id = (int)$this->getActivePlayerId();
        $this->bga->notify->all('currentPlayerCardsCountUpdate', '', [
            'player' => ['id' => $player_id, 'cards_count' => $this->getPlayerCardsCount($player_id)],
        ]);
    }

    public function dealCardsToPlayers(): void
    {
        $players = $this->loadPlayersBasicInfos();

        for ($i = 0; $i < 5; $i++) {
            foreach ($players as $player_id => $player) {
                $this->cards->pickCards(1, self::DECK, $player_id);
            }
        }

        foreach ($players as $player_id => $player) {
            $this->notifyPlayerAboutHisHand((int)$player_id);
        }
    }
}
