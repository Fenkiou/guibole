<?php

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');


class Guibole extends Table
{
  const DECK = 'deck';
  const HAND = 'hand';
  const DISCARD = 'discard';
  const DRAWED_CARDS = 'drawed_cards';

  function __construct()
  {
    parent::__construct();

    self::initGameStateLabels(array());

    $this->cards = self::getNew("module.common.deck");
    $this->cards->init("card");
  }

  protected function getGameName()
  {
    return "guibole";
  }

  protected function setupNewGame($players, $options = array())
  {
    // Set the colors of the players with HTML color code
    // The default below is red/green/blue/orange/brown
    // The number of colors defined here must correspond to the maximum number of players allowed for the game
    $gameinfos = self::getGameinfos();
    $default_colors = $gameinfos['player_colors'];

    // Create players
    $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
    $values = array();
    foreach ($players as $player_id => $player) {
      $color = array_shift($default_colors);
      $values[] = "('" . $player_id . "','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
    }
    $sql .= implode($values, ',');
    self::DbQuery($sql);

    self::reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
    self::reloadPlayersBasicInfos();

    /************ Start the game initialization *****/

    $cards = array();
    foreach ($this->colors as $color_id => $color) // diamond, club, heart, spade
    {
      //  K, Q, J, 10, ..., 2, A
      for ($value = 13; $value >= 1; $value--)
      {
        $cards[] = array('type' => $color_id, 'type_arg' => $value, 'nbr' => 1);
      }
    }

    $this->cards->createCards($cards, self::DECK);

    // Activate first player (which is in general a good idea :) )
    $this->activeNextPlayer();
    /************ End of the game initialization *****/
  }

  function getDrawedCards()
  {
    return $this->cards->getCardsInLocation(self::DRAWED_CARDS);
  }

  function setDrawedCards($cards)
  {
    $this->cards->moveCards(self::getCardIds($cards), self::DRAWED_CARDS);

    // Notify all other players about the discarded cards
    self::notifyAllPlayers('drawedCards', '', array(
      'cards' => $cards
    ));
  }

  function getDiscardedCards()
  {
    return $this->cards->getCardsInLocation(self::DISCARD);
  }

  function setDiscardedCards($cards)
  {
    self::dump("setDiscardedCards", $cards);
    $this->cards->moveCards(self::getCardIds($cards), self::DISCARD);

    self::notifyAllPlayers('discardedCards', '', array(
      'cards' => $cards
    ));
  }

  function getFirstCardInDeck()
  {
    return $this->cards->getCardOnTop(self::DECK);
  }

  function getCards($card_ids)
  {
    return $this->cards->getCards($card_ids);
  }

  function getCardIds($cards)
  {
    return array_column($cards, 'id');
  }

  function currentUserTakeCard($card_id)
  {
    $card = $this->cards->getCard($card_id);

    if (!$card)
      throw new feException(self::_("This card does not exists"));

    if ($card['location'] != self::DECK && $card['location'] != self::DISCARD)
      throw new feException(self::_("This card cannot be taken"));

    $current_player_id = self::getCurrentPlayerId();
    $this->cards->moveCard($card_id, self::HAND, $current_player_id);

    if ($card['location'] == self::DECK) {
      self::notifyAllPlayers('setFirstCardInDeck', '', array(
        'card' => self::getFirstCardInDeck()
      ));
    }

    $cards = $this->cards->getCardsInLocation(self::HAND, $current_player_id);

    self::notifyPlayer($current_player_id, 'newHand', '', array(
      'cards' => $cards
    ));
  }

  /*
        getAllDatas: 

        Gather all informations about current game situation (visible by the current player).

        The method is called each time the game interface is displayed to a player, ie:
        _ when the game starts
        _ when a player refreshes the game page (F5)
   */
  protected function getAllDatas()
  {
    $result = array();

    $current_player_id = self::getCurrentPlayerId();

    $sql = "SELECT player_id AS id, player_score AS score FROM player";
    $result['players'] = self::getCollectionFromDb($sql);

    $result[self::HAND] = $this->cards->getCardsInLocation(self::HAND, $current_player_id);

    $result['first_card_in_deck'] = self::getFirstCardInDeck();
    $result[self::DISCARD] = self::getDiscardedCards();
    $result[self::DRAWED_CARDS] = self::getDrawedCards();

    $result['state_name'] = $this->gamestate->state()["name"];

    return $result;
  }

  function startRound()
  {
    $this->cards->moveAllCardsInLocation(null, self::DECK);
    $this->cards->shuffle(self::DECK);

    $players = self::loadPlayersBasicInfos();

    foreach ($players as $player_id => $player) {
      $this->cards->pickCards(5, self::DECK, $player_id);
    }

    $cards = array($this->cards->pickCardForLocation(self::DECK, self::DISCARD));
    self::setDiscardedCards($cards);

    $this->gamestate->nextState("playerTurn");
  }

  function playCards($card_ids)
  {
    $current_player_id = self::getCurrentPlayerId();

    $cards = self::getCards($card_ids);

    if (count($cards) != count($card_ids))
      throw new feException(self::_("Some of these cards don't exist"));

    foreach ($cards as $card) {
      if ($card['location'] != self::HAND || $card['location_arg'] != $current_player_id)
        throw new feException(self::_("Some of these cards are not in your hand"));
    }

    foreach ($card_ids as $card_id)
      $this->cards->playCard($card_id);

    self::setDrawedCards($cards);

    $cards = $this->cards->getCardsInLocation(self::HAND, $current_player_id);

    // Notify player about his cards
    self::notifyPlayer($current_player_id, 'newHand', '', array(
      'cards' => $cards
    ));

    $this->gamestate->nextState("playedCards");
  }

  function endTurn($card_id)
  {
    self::currentUserTakeCard($card_id);

    $drawed_cards = self::getDrawedCards();
    self::setDiscardedCards($drawed_cards);
    self::setDrawedCards(array());

    $this->gamestate->nextState("nextPlayer");
  }

  function nextPlayer()
  {
    $player_id = self::activeNextPlayer();
    self::giveExtraTime($player_id);

    $this->gamestate->nextState("playerTurn");
  }

  function showCards()
  {
    $this->gamestate->nextState("endRound");
  }

  function endRound()
  {
    $this->gamestate->nextState("gameEnd");
  }

  function getGameProgression()
  {
    // TODO: compute and return the game progression

    return 0;
  }


  function zombieTurn($state, $active_player)
  {
    $statename = $state['name'];

    if ($state['type'] === "activeplayer") {
      switch ($statename) {
        default:
          $this->gamestate->nextState("zombiePass");
          break;
      }

      return;
    }

    if ($state['type'] === "multipleactiveplayer") {
      // Make sure player is in a non blocking status for role turn
      $this->gamestate->setPlayerNonMultiactive($active_player, '');

      return;
    }

    throw new feException("Zombie mode not supported at this game state: " . $statename);
  }

  function upgradeTableDb($from_version)
  {
  }
}
