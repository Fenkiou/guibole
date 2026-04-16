<?php

declare(strict_types=1);

namespace Bga\Games\guibole\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\guibole\Game;
use Bga\Games\guibole\StateConstants;

class DrawCard extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: StateConstants::STATE_DRAW_CARD,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must draw a card'),
            descriptionMyTurn: clienttranslate('${you} must draw a card'),
            transitions: [
                'next' => StateConstants::STATE_ACTIVATE_NEXT_PLAYER,
            ],
        );
    }

    #[PossibleAction]
    public function action_drawCard(?int $id, int $active_player_id): string
    {
        $game = $this->game;

        $game->currentUserTakeCard($id);

        $game->setDiscardedCards($game->getPlayedCards(), true);

        $game->notifyAllPlayersAboutCurrentPlayerCardsCount();

        return ActivateNextPlayer::class;
    }

    public function zombie(int $playerId): string
    {
        return ActivateNextPlayer::class;
    }
}
