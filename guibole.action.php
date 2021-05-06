<?php

class action_guibole extends APP_GameAction
{
  // Constructor: please do not modify
  public function __default()
  {
    if (self::isArg('notifwindow')) {
      $this->view = "common_notifwindow";
      $this->viewArgs['table'] = self::getArg("table", AT_posint, true);
    } else {
      $this->view = "guibole_guibole";
      self::trace("Complete reinitialization of board game");
    }
  }

  public function playCards()
  {
    self::trace("Play cards");
    self::setAjaxMode();

    $raw_card_ids = self::getArg("ids", AT_numberlist, true);

    // Removing last ';' if exists
    if (substr($raw_card_ids, -1) == ';')
      $raw_card_ids = substr($raw_card_ids, 0, -1);
    if ($raw_card_ids == '')
      $card_ids = array();
    else
      $card_ids = explode(';', $raw_card_ids);

    $this->game->playCards($card_ids);
    self::ajaxResponse();
  }

  public function drawCard()
  {
    self::trace("drawCard");
    self::setAjaxMode();
    $card_id = self::getArg("id", AT_posint, true);
    $this->game->drawCard($card_id);
    self::ajaxResponse();
  }

  public function endRound()
  {
    self::trace("endRound");
    self::setAjaxMode();
    $this->game->endRound();
    self::ajaxResponse();
  }
}
