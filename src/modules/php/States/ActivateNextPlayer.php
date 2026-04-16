<?php

declare(strict_types=1);

namespace Bga\Games\guibole\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\guibole\Game;
use Bga\Games\guibole\StateConstants;

class ActivateNextPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: StateConstants::STATE_ACTIVATE_NEXT_PLAYER,
            type: StateType::GAME,
            transitions: [
                'next' => StateConstants::STATE_PLAY_CARDS_OR_END_ROUND,
            ],
        );
    }

    public function onEnteringState()
    {
        $player_id = $this->game->activeNextPlayer();
        $this->game->giveExtraTime($player_id);

        return PlayCardsOrEndRound::class;
    }
}
