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

    self::initGameStateLabels(array(
      "startingPlayerId" => 10,
      "gameLengthOption" => 100,
    ));

    $this->cards = self::getNew("module.common.deck");
    $this->cards->init("card");
  }

  protected function getGameName()
  {
    return "guibole";
  }

  protected function setupNewGame($players, $options = array())
  {
    $gameinfos = self::getGameinfos();

    // Set the colors of the players with HTML color code
    // The default below is red/green/blue/orange/brown
    // The number of colors defined here must correspond to the maximum number of players allowed for the game
    $default_colors = $gameinfos['player_colors'];

    $starting_score = self::getGameStateValue('gameLengthOption') == 1 ? 100 : 500;


    // Create players
    $sql = "INSERT INTO player (player_id, player_score, player_color, player_canal, player_name, player_avatar) VALUES ";
    $values = array();
    foreach ($players as $player_id => $player) {
      $color = array_shift($default_colors);
      $values[] = "('" . $player_id . "','$starting_score','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
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
      for ($value = 13; $value >= 1; $value--) {
        $cards[] = array('type' => $color_id, 'type_arg' => $value, 'nbr' => 1);
      }
    }

    $this->cards->createCards($cards, self::DECK);

    // Activate first player (which is in general a good idea :) )
    $this->activeNextPlayer();

    self::setGameStateInitialValue('startingPlayerId', self::getActivePlayerId());

    /************ End of the game initialization *****/
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

    $result['players'] = self::getPlayersData();

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
      self::notifyPlayerAboutHisHand($player_id);
    }

    $cards = array($this->cards->pickCardForLocation(self::DECK, self::DISCARD));
    self::setDiscardedCards($cards);
    self::notifyFirstCardInDeck();

    self::dump("startingPlayerId", self::getGameStateValue("startingPlayerId"));
    $this->gamestate->changeActivePlayer(self::getGameStateValue("startingPlayerId"));
    $this->gamestate->nextState("playerTurn");
  }

  function playCards($card_ids)
  {
    $current_player_id = self::getCurrentPlayerId();

    $cards = self::getCards($card_ids);

    if (count($cards) != count($card_ids))
      throw new feException(self::_("Some of these cards don't exist"));

    $card_values = array();

    foreach ($cards as $card) {
      if ($card['location'] != self::HAND || $card['location_arg'] != $current_player_id)
        throw new feException(self::_("Some of these cards are not in your hand"));

      if (!count($card_values)) {
        array_push($card_values, self::getCardValue($card));
        continue;
      }

      if (!in_array(self::getCardValue($card), $card_values)) {
        throw new feException(self::_("You can only play multiple card of same value"));
      }
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

    $drawed_cards = $this->getDrawedCards();
    $this->setDiscardedCards($drawed_cards);
    $this->setDrawedCards(array());
    $this->notifyAllPlayersAboutCurrentPlayerCardsCount();

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
    self::ensureCurrentPlayer();

    $current_player_id = self::getCurrentPlayerId();
    $current_player_hand_points = self::getHandPointsForPlayerId($current_player_id);

    $players_with_points = array();

    if ($current_player_hand_points > 10) {
      $players_with_points[$current_player_id] = 45;
    }

    $players = self::loadPlayersBasicInfos();

    if (!count($players_with_points)) {
      foreach ($players as $player_id => $player) {
        if ($player_id == $current_player_id)
          continue;

        $player_hand_points = self::getHandPointsForPlayerId($player_id);

        if ($player_hand_points <= $current_player_hand_points) {
          $players_with_points[$current_player_id] = $current_player_hand_points * 2 + 25;
          break;
        }
      }
    }

    if (!count($players_with_points)) {
      foreach ($players as $player_id => $player) {
        if ($player_id == $current_player_id)
          continue;

        $player_hand_points = self::getHandPointsForPlayerId($player_id);
        $players_with_points[$player_id] = $player_hand_points;
      }
    }

    foreach ($players_with_points as $player_id => $player_points) {
      self::updateScoreForPlayer($player_id, $player_points);
    }

    $end_game = false;
    foreach ($players as $player_id => $player) {
      if (self::getPlayerScore($player_id) <= 0) {
        $end_game = true;
        break;
      }
    }

    self::notifyPlayersAboutScores();

    if (!$end_game) {
      self::setGameStateValue("startingPlayerId", self::getPlayerAfter(self::getActivePlayerId()));
      $this->gamestate->nextState("startRound");
    } else {
      $this->gamestate->nextState("gameEnd");
    }
  }

  function getGameProgression()
  {
    $players = $this->getPlayersData();

    $game_length = $this->getGameStateValue('gameLengthOption') == 1 ? 100 : 500;
    $lowest_score = $game_length;

    foreach ($players as $player_id => $player) {
      if ($player["score"] < $lowest_score) {
        $lowest_score = $player["score"];
      }
    }

    if ($lowest_score == $game_length) {
      return 0;
    }

    return (($game_length - $lowest_score) * 100) / $game_length;
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

    throw new feException("Zombie mode not supported at this game state: " . $statename);
  }

  function upgradeTableDb($from_version)
  {
  }


  /*
   * Utils
   */
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

  function notifyFirstCardInDeck()
  {
    self::notifyAllPlayers('setFirstCardInDeck', '', array(
      'card' => self::getFirstCardInDeck()
    ));
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
    self::ensureCurrentPlayer();

    $card = $this->cards->getCard($card_id);

    if (!$card)
      throw new feException(self::_("This card does not exists"));

    if ($card['location'] != self::DECK && $card['location'] != self::DISCARD)
      throw new feException(self::_("This card cannot be taken"));

    $current_player_id = self::getCurrentPlayerId();
    $this->cards->moveCard($card_id, self::HAND, $current_player_id);

    if ($card['location'] == self::DECK) {
      self::notifyFirstCardInDeck();
    }

    self::notifyPlayerAboutHisHand($current_player_id);
  }

  function notifyPlayerAboutHisHand($player_id)
  {
    self::notifyPlayer($player_id, 'newHand', '', array(
      'cards' => $this->cards->getCardsInLocation(self::HAND, $player_id)
    ));
  }

  function getCardValue($card)
  {
    $card_value = (int) $card['type_arg'];

    if ($card_value > 10)
      $card_value = 10;

    return $card_value;
  }

  function ensureCurrentPlayer()
  {
    if (self::getActivePlayerId() != self::getCurrentPlayerId()) {
      throw new feException(self::_("This is not your turn."));
    }
  }

  function getHandPointsForPlayerId($player_id)
  {
    $cards = $this->cards->getCardsInLocation(self::HAND, $player_id);
    $points = 0;

    foreach ($cards as $card)
      $points += self::getCardValue($card);

    return $points;
  }

  function getPlayerScore($player_id)
  {
    return self::getUniqueValueFromDB('SELECT player_score FROM player WHERE player_id = ' . $player_id);
  }

  function updateScoreForPlayer($player_id, $points)
  {
    $new_score = self::getPlayerScore($player_id) - $points;
    self::DbQuery('UPDATE player SET player_score = ' . $new_score . ' WHERE player_id = ' . $player_id);
    return $new_score;
  }

  function getPlayersData()
  {
    $players = self::getCollectionFromDb('SELECT player_id AS id, player_score AS score FROM player');
    foreach ($players as $player_id => &$player) {
      $player["cards_count"] = $this->getPlayerCardsCount($player_id);
    }
    return $players;
  }

  function getPlayerCardsCount($player_id)
  {
    return count($this->cards->getCardsInLocation(self::HAND, $player_id));
  }

  function notifyPlayersAboutScores()
  {
    self::notifyAllPlayers('updateScore', '', array(
      'players' => self::getPlayersData()
    ));
  }

  function notifyAllPlayersAboutCurrentPlayerCardsCount()
  {
    $player_id = $this->getCurrentPlayerId();
    $this->notifyAllPlayers('currentPlayerCardsCountUpdate', '', array(
      'player' => array("id" => $player_id, "cards_count" => $this->getPlayerCardsCount($player_id))
    ));
  }
}
