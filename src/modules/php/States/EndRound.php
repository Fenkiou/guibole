<?php

declare(strict_types=1);

namespace Bga\Games\guibole\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\guibole\Game;
use Bga\Games\guibole\StateConstants;

class EndRound extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: StateConstants::STATE_END_ROUND,
            type: StateType::GAME,
            transitions: [
                'nextRound' => StateConstants::STATE_START_ROUND,
                'endGame'   => StateConstants::STATE_END_GAME,
            ],
            updateGameProgression: true,
        );
    }

    public function onEnteringState()
    {
        $players = $this->game->loadPlayersBasicInfos();

        foreach ($players as $player_id => $player) {
            if ($this->game->getPlayerScore((int) $player_id) <= 0) {
                return StateConstants::STATE_END_GAME;
            }
        }

        return StartRound::class;
    }
}
