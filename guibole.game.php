<?php

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');


class Guibole extends Table
{
  const DECK = 'deck';
  const HAND = 'hand';
  const DISCARD = 'discard';
  const TMP_DISCARD = 'tmp_discard';
  const DRAWED_CARDS = 'drawed_cards';

  function __construct()
  {
    parent::__construct();

    $this->initGameStateLabels(array(
      "startingPlayerId" => 10,
      "gameLengthOption" => 100,
    ));

    $this->cards = $this->getNew("module.common.deck");
    $this->cards->init("card");
  }

  protected function getGameName()
  {
    return "guibole";
  }

  protected function setupNewGame($players, $options = array())
  {
    $gameinfos = $this->getGameinfos();

    // Set the colors of the players with HTML color code
    // The default below is red/green/blue/orange/brown
    // The number of colors defined here must correspond to the maximum number of players allowed for the game
    $default_colors = $gameinfos['player_colors'];

    $starting_score = $this->getGameStateValue('gameLengthOption') == 1 ? 100 : 500;


    // Create players
    $sql = "INSERT INTO player (player_id, player_score, player_color, player_canal, player_name, player_avatar) VALUES ";
    $values = array();
    foreach ($players as $player_id => $player) {
      $color = array_shift($default_colors);
      $values[] = "('" . $player_id . "','$starting_score','$color','" . $player['player_canal'] . "','" . addslashes($player['player_name']) . "','" . addslashes($player['player_avatar']) . "')";
    }
    $sql .= implode($values, ',');
    $this->DbQuery($sql);

    $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
    $this->reloadPlayersBasicInfos();

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

    $this->setGameStateInitialValue('startingPlayerId', $this->getActivePlayerId());

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

    $current_player_id = $this->getCurrentPlayerId();

    $result['players'] = $this->getPlayersData();

    $result[self::HAND] = $this->cards->getCardsInLocation(self::HAND, $current_player_id);

    $result['first_card_in_deck'] = $this->getFirstCardInDeck();
    $result[self::DISCARD] = $this->getDiscardedCards();
    $result[self::DRAWED_CARDS] = $this->getDrawedCards();

    $result['state_name'] = $this->gamestate->state()["name"];

    return $result;
  }

  function startRound()
  {
    $this->cards->moveAllCardsInLocation(null, self::DECK);
    $this->cards->shuffle(self::DECK);

    $this->dealCardsToPlayers();

    $cards = array($this->cards->pickCardForLocation(self::DECK, self::TMP_DISCARD));
    $this->setDiscardedCards($cards);
    $this->setShowedCards(array());
    $this->notifyFirstCardInDeck();

    $this->gamestate->changeActivePlayer($this->getGameStateValue("startingPlayerId"));
    $this->gamestate->nextState("playerTurn");
  }

  function playCards($card_ids)
  {
    $current_player_id = $this->getCurrentPlayerId();

    $cards = $this->getCards($card_ids);

    if (count($cards) != count($card_ids))
      throw new feException(self::_("Some of these cards don't exist"));

    $card_values = array();

    foreach ($cards as $card) {
      if ($card['location'] != self::HAND || $card['location_arg'] != $current_player_id)
        throw new feException(self::_("Some of these cards are not in your hand"));

      if (!count($card_values)) {
        array_push($card_values, $this->getCardValue($card));
        continue;
      }

      if (!in_array($this->getCardValue($card), $card_values)) {
        throw new feException(self::_("You can only play multiple card of same value"));
      }
    }

    foreach ($card_ids as $card_id)
      $this->cards->playCard($card_id);

    $this->setDrawedCards($cards);

    $cards = $this->cards->getCardsInLocation(self::HAND, $current_player_id);

    // Notify player about his cards
    $this->notifyPlayer($current_player_id, 'newHand', '', array(
      'cards' => $cards
    ));

    $this->gamestate->nextState("playedCards");
  }

  function endTurn($card_id)
  {
    $this->currentUserTakeCard($card_id);

    $drawed_cards = $this->getDrawedCards();
    $this->setDiscardedCards($drawed_cards);
    $this->setDrawedCards(array());
    $this->notifyAllPlayersAboutCurrentPlayerCardsCount();

    $this->gamestate->nextState("nextPlayer");
  }

  function nextPlayer()
  {
    $player_id = $this->activeNextPlayer();
    $this->giveExtraTime($player_id);

    $this->gamestate->nextState("playerTurn");
  }

  function showCards()
  {
    $this->gamestate->nextState("endRound");
  }

  function endRound()
  {
    $this->ensureCurrentPlayer();

    $current_player_id = $this->getCurrentPlayerId();
    $current_player_hand_points = $this->getHandPointsForPlayerId($current_player_id);

    $players_with_points = array();

    if ($current_player_hand_points > 10) {
      $players_with_points[$current_player_id] = 45;
    }

    $players = $this->loadPlayersBasicInfos();

    if (!count($players_with_points)) {
      foreach ($players as $player_id => $player) {
        if ($player_id == $current_player_id)
          continue;

        $player_hand_points = $this->getHandPointsForPlayerId($player_id);

        if ($player_hand_points <= $current_player_hand_points) {
          $players_with_points[$current_player_id] = $current_player_hand_points * 2 + 25;
        } else {
          $players_with_points[$player_id] = $player_hand_points;
        }
      }
    }

    foreach ($players_with_points as $player_id => $player_points) {
      $this->updateScoreForPlayer($player_id, $player_points);
    }

    $end_game = false;
    foreach ($players as $player_id => $player) {
      if ($this->getPlayerScore($player_id) <= 0) {
        $end_game = true;
        break;
      }
    }

    $this->notifyPlayersAboutScores($this->cards->getCardsInLocation(self::HAND, $current_player_id));

    if (!$end_game) {
      $this->setGameStateValue("startingPlayerId", $this->getPlayerAfter($this->getActivePlayerId()));
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
    $this->cards->moveCards($this->getCardIds($cards), self::DRAWED_CARDS);

    // Notify all other players about the discarded cards
    $this->notifyAllPlayers('drawedCards', '', array(
      'cards' => $cards
    ));

    if (!count($cards))
      return;

    $card_count = _('one');
    if (count($cards) == 2)
      $card_count = _('two');
    if (count($cards) == 3)
      $card_count = _('three');
    if (count($cards) == 4)
      $card_count = _('four');

    $this->notifyAllPlayers(
      'message',
      _('${player_name} played ${card_count} ${card_value}'),
      array(
        'player_id' => $this->getActivePlayerId(),
        'player_name' => $this->getActivePlayerName(),
        'card_count' => $card_count,
        'card_value' => $this->getCardHumanReadableValue(array_values($cards)[0]),
        'i18n' => array('card_count'),
      )
    );
  }

  function getDiscardedCards()
  {
    return $this->cards->getCardsInLocation(self::TMP_DISCARD);
  }

  function setDiscardedCards($cards)
  {
    $this->cards->moveAllCardsInLocation(self::TMP_DISCARD, self::DISCARD);
    $this->cards->moveCards($this->getCardIds($cards), self::TMP_DISCARD);

    $this->notifyAllPlayers('discardedCards', '', array(
      'cards' => $cards
    ));
  }

  function setShowedCards($cards)
  {
    $this->notifyAllPlayers('showedCards', '', array(
      'cards' => $cards,
    ));
  }

  function getFirstCardInDeck()
  {
    return $this->cards->getCardOnTop(self::DECK);
  }

  function notifyFirstCardInDeck()
  {
    $this->notifyAllPlayers('setFirstCardInDeck', '', array(
      'card' => $this->getFirstCardInDeck()
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
    $this->ensureCurrentPlayer();

    $card = $this->cards->getCard($card_id);

    if (!$card)
      throw new feException(self::_("This card does not exists"));

    if ($card['location'] != self::DECK && $card['location'] != self::TMP_DISCARD)
      throw new feException(self::_("This card cannot be taken"));

    $current_player_id = $this->getCurrentPlayerId();
    $this->cards->moveCard($card_id, self::HAND, $current_player_id);

    if ($card['location'] == self::DECK) {
      $this->notifyFirstCardInDeck();

      $this->notifyAllPlayers(
        'message',
        _('${player_name} took a card from the deck'),
        array(
          'player_id' => $this->getActivePlayerId(),
          'player_name' => $this->getActivePlayerName(),
        )
      );
    } else {
      $this->notifyAllPlayers(
        'message',
        _('${player_name} took a ${card_value} from the discard'),
        array(
          'player_id' => $this->getActivePlayerId(),
          'player_name' => $this->getActivePlayerName(),
          'card_value' => $this->getCardHumanReadableValue($card),
        )
      );
    }

    $this->notifyPlayerAboutHisHand($current_player_id);
  }

  function notifyPlayerAboutHisHand($player_id)
  {
    $this->notifyPlayer($player_id, 'newHand', '', array(
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
    if ($this->getActivePlayerId() != $this->getCurrentPlayerId()) {
      throw new feException(self::_("This is not your turn."));
    }
  }

  function getHandPointsForPlayerId($player_id)
  {
    $cards = $this->cards->getCardsInLocation(self::HAND, $player_id);
    $points = 0;

    foreach ($cards as $card)
      $points += $this->getCardValue($card);

    return $points;
  }

  function getPlayerScore($player_id)
  {
    return $this->getUniqueValueFromDB('SELECT player_score FROM player WHERE player_id = ' . $player_id);
  }

  function updateScoreForPlayer($player_id, $points)
  {
    $new_score = $this->getPlayerScore($player_id) - $points;
    $this->DbQuery('UPDATE player SET player_score = ' . $new_score . ' WHERE player_id = ' . $player_id);
    return $new_score;
  }

  function getPlayersData()
  {
    $players = $this->getCollectionFromDb('SELECT player_id AS id, player_score AS score FROM player');
    foreach ($players as $player_id => &$player) {
      $player["cards_count"] = $this->getPlayerCardsCount($player_id);
    }
    return $players;
  }

  function getPlayerCardsCount($player_id)
  {
    return count($this->cards->getCardsInLocation(self::HAND, $player_id));
  }

  function notifyPlayersAboutScores($showedCards)
  {
    $this->notifyAllPlayers('updateScore', '', array(
      'players' => $this->getPlayersData(),
      'cards' => $showedCards
    ));
  }

  function notifyAllPlayersAboutCurrentPlayerCardsCount()
  {
    $player_id = $this->getCurrentPlayerId();
    $this->notifyAllPlayers('currentPlayerCardsCountUpdate', '', array(
      'player' => array("id" => $player_id, "cards_count" => $this->getPlayerCardsCount($player_id))
    ));
  }

  function dealCardsToPlayers()
  {
    $players = $this->loadPlayersBasicInfos();

    for ($i = 0; $i < 5; $i++) {
      foreach ($players as $player_id => $player) {
        $this->cards->pickCards(1, self::DECK, $player_id);
      }
    }

    foreach ($players as $player_id => $player) {
      $this->notifyPlayerAboutHisHand($player_id);
    }
  }

  function getCardHumanReadableValue($card)
  {
    if ($card['type_arg'] == 1)
      return 'A';
    if ($card['type_arg'] == 11)
      return 'J';
    if ($card['type_arg'] == 12)
      return 'Q';
    if ($card['type_arg'] == 13)
      return 'K';

    return $card['type_arg'];
  }
}
