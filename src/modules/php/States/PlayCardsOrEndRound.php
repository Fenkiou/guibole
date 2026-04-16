<?php

declare(strict_types=1);

namespace Bga\Games\guibole\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\guibole\Game;
use Bga\Games\guibole\StateConstants;

class PlayCardsOrEndRound extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: StateConstants::STATE_PLAY_CARDS_OR_END_ROUND,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must play cards or end the round'),
            descriptionMyTurn: clienttranslate('${you} must play cards or end the round'),
            transitions: [
                'playCards' => StateConstants::STATE_DRAW_CARD,
                'endRound'  => StateConstants::STATE_END_ROUND,
                'zombiePass' => StateConstants::STATE_ZOMBIE_PASS,
            ],
        );
    }

    #[PossibleAction]
    public function action_playCards(string $ids, int $active_player_id): string
    {
        $game = $this->game;

        $card_ids = array_filter(array_map('intval', explode(';', $ids)), fn($id) => $id > 0);

        $cards = $game->cards->getCards($card_ids);

        if (count($cards) !== count($card_ids)) {
            throw new \Bga\GameFramework\UserException(clienttranslate("Some of these cards don't exist"));
        }

        $card_values = [];

        foreach ($cards as $card) {
            if ($card['location'] !== $game::HAND || (int)$card['location_arg'] !== $active_player_id) {
                throw new \Bga\GameFramework\UserException(clienttranslate("Some of these cards are not in your hand"));
            }

            $val = $game->getCardValue($card);
            if (!count($card_values)) {
                $card_values[] = $val;
                continue;
            }

            if (!in_array($val, $card_values)) {
                throw new \Bga\GameFramework\UserException(clienttranslate("You can only play multiple card of same value"));
            }
        }

        $game->setPlayedCards($cards);

        return DrawCard::class;
    }

    #[PossibleAction]
    public function action_endRound(int $active_player_id): string
    {
        $this->game->doEndRound($active_player_id);
        return EndRound::class;
    }

    public function zombie(int $playerId): string
    {
        return ZombiePass::class;
    }
}
