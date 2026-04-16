<?php

declare(strict_types=1);

namespace Bga\Games\guibole;

class StateConstants
{
    const STATE_START_ROUND = 20;
    const STATE_PLAY_CARDS_OR_END_ROUND = 21;
    const STATE_DRAW_CARD = 22;
    const STATE_ACTIVATE_NEXT_PLAYER = 24;
    const STATE_END_ROUND = 30;
    const STATE_ZOMBIE_PASS = 98;
    const STATE_END_GAME = 99;
}
