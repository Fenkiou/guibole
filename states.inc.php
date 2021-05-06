<?php

$machinestates = array(

  // The initial state. Please do not modify.
  1 => array(
    "name" => "gameSetup",
    "description" => "",
    "type" => "manager",
    "action" => "stGameSetup",
    "transitions" => array("" => 20)
  ),

  20 => array(
    "name" => "startRound",
    "description" => "",
    "type" => "game",
    "action" => "startRound",
    "transitions" => array("playerTurn" => 21)
  ),

  21 => array(
    "name" => "playerTurn",
    "description" => clienttranslate('${actplayer} must play cards or end round'),
    "descriptionmyturn" => clienttranslate('${you} must play cards or end round'),
    "type" => "activeplayer",
    "possibleactions" => array("endRound", "playedCards", "zombiePass"),
    "transitions" => array("endRound" => 30, "playedCards" => 22, "zombiePass" => 98)
  ),

  22 => array(
    "name" => "playedCards",
    "description" => clienttranslate('${actplayer} must take a card from deck or discard'),
    "descriptionmyturn" => clienttranslate('${you} must take a card from deck or discard'),
    "type" => "activeplayer",
    "possibleactions" => array("nextPlayer"),
    "transitions" => array("nextPlayer" => 24)
  ),

  24 => array(
    "name" => "nextPlayer",
    "description" => "",
    "type" => "game",
    "action" => "nextPlayer",
    "transitions" => array("playerTurn" => 21)
  ),

  30 => array(
    "name" => "endRound",
    "description" => "",
    "type" => "game",
    "action" => "endRound",
    "transitions" => array("startRound" => 20, "gameEnd" => 99)
  ),

  98 => array(
    "name" => "zombiePass",
    "description" => "",
    "type" => "game",
    "action" => "nextPlayer",
    "transitions" => array("playerTurn" => 21)
  ),

  // Final state.
  // Please do not modify (and do not overload action/args methods).
  99 => array(
    "name" => "gameEnd",
    "description" => clienttranslate("End of game"),
    "type" => "manager",
    "action" => "stGameEnd",
    "args" => "argGameEnd"
  )

);
