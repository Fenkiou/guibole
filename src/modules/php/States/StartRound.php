<?php

declare(strict_types=1);

namespace Bga\Games\guibole\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\guibole\Game;
use Bga\Games\guibole\StateConstants;

class StartRound extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: StateConstants::STATE_START_ROUND,
            type: StateType::GAME,
            transitions: [
                'next' => StateConstants::STATE_PLAY_CARDS_OR_END_ROUND,
            ],
            updateGameProgression: true,
        );
    }

    public function onEnteringState()
    {
        $game = $this->game;

        $game->cards->moveAllCardsInLocation(null, $game::DECK);
        $game->cards->shuffle($game::DECK);

        $game->dealCardsToPlayers();

        $cards = [$game->cards->pickCardForLocation($game::DECK, $game::TMP_DISCARD)];
        $game->setDiscardedCards($cards, false);
        $game->setPlayedCards([]);

        $this->bga->notify->all(
            'message',
            clienttranslate('The dealer draws a ${card_value} and discards it'),
            [
                'card_value' => $game->getCardHumanReadableValue($cards[0]),
            ]
        );
        $this->bga->notify->all(
            'deckCountUpdate',
            '',
            [
                'deck_count' => $game->cards->countCardsInLocation($game::DECK),
            ]
        );

        $game->incStat(1, 'rounds_count');

        $game->gamestate->changeActivePlayer((int) $game->getGameStateValue('startingPlayerId'));

        return PlayCardsOrEndRound::class;
    }
}
