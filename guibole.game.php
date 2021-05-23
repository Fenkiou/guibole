<?php

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');


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

class Guibole extends Table
{
  const DECK = 'deck';
  const HAND = 'hand';
  const DISCARD = 'discard';
  const TMP_DISCARD = 'tmp_discard';
  const PLAYED_CARDS = 'played_cards';

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

    // Init statistics
    $this->initStat("table", "rounds_count", 0);
    $this->initStat("player", "ended_round_count", 0);
    $this->initStat("player", "failed_round_count", 0);
    $this->initStat("player", "counter_count", 0);

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

    $result[self::DISCARD] = $this->getDiscardedCards();
    $result[self::PLAYED_CARDS] = $this->getPlayedCards();

    $result['state_name'] = $this->gamestate->state()["name"];

    return $result;
  }

  function startRound()
  {
    $this->cards->moveAllCardsInLocation(null, self::DECK);
    $this->cards->shuffle(self::DECK);

    $this->dealCardsToPlayers();

    $cards = array($this->cards->pickCardForLocation(self::DECK, self::TMP_DISCARD));
    $this->setDiscardedCards($cards, false);
    $this->setPlayedCards(array());

    $this->notifyAllPlayers(
      'message',
      clienttranslate('The dealer draws a ${card_value} and discards it'),
      array(
        'card_value' => getCardHumanReadableValue($cards[0]),
      )
    );

    $this->incStat(1, "rounds_count");

    $this->gamestate->changeActivePlayer($this->getGameStateValue("startingPlayerId"));
    $this->gamestate->nextState("playCardsOrEndRoundState");
  }

  function playCards($card_ids)
  {
    $current_player_id = $this->getActivePlayerId();

    $cards = $this->getCards($card_ids);

    if (count($cards) != count($card_ids))
      throw new feException(clienttranslate("Some of these cards don't exist"));

    $card_values = array();

    foreach ($cards as $card) {
      if ($card['location'] != self::HAND || $card['location_arg'] != $current_player_id)
        throw new feException(clienttranslate("Some of these cards are not in your hand"));

      if (!count($card_values)) {
        array_push($card_values, $this->getCardValue($card));
        continue;
      }

      if (!in_array($this->getCardValue($card), $card_values)) {
        throw new feException(clienttranslate("You can only play multiple card of same value"));
      }
    }

    $this->setPlayedCards($cards);

    $this->gamestate->nextState("drawCardState");
  }

  function drawCard($card_id)
  {
    $this->currentUserTakeCard($card_id);

    $this->setDiscardedCards($this->getPlayedCards(), true);

    $this->notifyAllPlayersAboutCurrentPlayerCardsCount();

    $this->gamestate->nextState("activateNextPlayerState");
  }

  function activateNextPlayerState()
  {
    $player_id = $this->activeNextPlayer();
    $this->giveExtraTime($player_id);

    $this->gamestate->nextState("playCardsOrEndRoundState");
  }

  function endRound()
  {
    $this->ensureCurrentPlayer();

    $current_player_id = $this->getActivePlayerId();
    $current_player_name = $this->getActivePlayerName();
    $current_player_hand_points = $this->getHandPointsForPlayerId($current_player_id);

    $players_with_points = array();

    $eliminating_hand = false;

    if ($current_player_hand_points > 10) {
      $players_with_points[$current_player_id] = 45;
      $eliminating_hand = true;
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

    // Announcing cards and score of player who showed his cards
    if (isset($players_with_points[$current_player_id])) {
      $message = clienttranslate('${player_name} shows: ${card_values} and loose ${hand_point} points');
      $points = $players_with_points[$current_player_id];
      $this->incStat(1, "failed_round_count", $current_player_id);
    } else {
      $message = clienttranslate('${player_name} shows: ${card_values} and do not loose point');
      $points = 0;
    }
    $this->notifyAllPlayers(
      'message',
      $message,
      array(
        'player_name' => $current_player_name,
        'card_values' => join(
          ', ',
          array_map(
            'getCardHumanReadableValue',
            array_values($this->getPlayerCards($current_player_id))
          )
        ),
        'hand_point' => $points
      )
    );

    foreach ($players as $player_id => $player) {
      if ($player_id == $current_player_id)
        continue;

      if (isset($players_with_points[$player_id])) {
        $message = clienttranslate('${player_name} have: ${card_values} and loose ${hand_point} points');
      } else if ($eliminating_hand) {
        $message = clienttranslate('${player_name} have: ${card_values} and do not loose points');
      } else {
        $message = clienttranslate('${player_name} have: ${card_values} and counter ${current_player_name}');
        $this->incStat(1, "counter_count", $player_id);
      }

      $this->notifyAllPlayers(
        'message',
        $message,
        array(
          'player_name' => $player['player_name'],
          'card_values' => join(
            ', ',
            array_map(
              'getCardHumanReadableValue',
              array_values($this->getPlayerCards($player_id))
            )
          ),
          'hand_point' => $this->getHandPointsForPlayerId($player_id),
          'current_player_name' => $current_player_name
        )
      );
    }

    foreach ($players_with_points as $player_id => $player_points) {
      $this->updateScoreForPlayer($player_id, $player_points);
    }

    $this->notifyPlayersAboutScores(
      $this->cards->getCardsInLocation(self::HAND, $current_player_id),
      $current_player_id
    );

    $this->incStat(1, "ended_round_count", $current_player_id);

    $this->setGameStateValue("startingPlayerId", $this->getPlayerAfter($this->getGameStateValue("startingPlayerId")));
    $this->gamestate->nextState("endRoundState");
  }

  function endRoundState()
  {
    $players = $this->loadPlayersBasicInfos();

    $end_game = false;
    foreach ($players as $player_id => $player) {
      if ($this->getPlayerScore($player_id) <= 0) {
        $end_game = true;
        break;
      }
    }

    if (!$end_game) {
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
  function getPlayedCards()
  {
    return $this->cards->getCardsInLocation(self::PLAYED_CARDS);
  }

  function setPlayedCards($cards)
  {
    $this->cards->moveCards($this->getCardIds($cards), self::PLAYED_CARDS);

    $this->notifyAllPlayers('playedCards', '', array(
      'cards' => $cards,
      'player_id' => $this->getActivePlayerId()
    ));

    if (!count($cards))
      return;

    $this->notifyAllPlayers(
      'message',
      clienttranslate('${player_name} plays: ${card_values}'),
      array(
        'player_id' => $this->getActivePlayerId(),
        'player_name' => $this->getActivePlayerName(),
        'card_values' => join(
          ', ',
          array_map(
            'getCardHumanReadableValue',
            array_values($cards)
          )
        ),
      )
    );
  }

  function getDiscardedCards()
  {
    return $this->cards->getCardsInLocation(self::TMP_DISCARD);
  }

  function setDiscardedCards($cards, $reset_played_cards)
  {
    $this->cards->moveAllCardsInLocation(self::TMP_DISCARD, self::DISCARD);
    $this->cards->moveCards($this->getCardIds($cards), self::TMP_DISCARD);

    $from = self::DECK;
    if ($reset_played_cards) {
      $from = self::PLAYED_CARDS;
    }

    $this->notifyAllPlayers('discardedCards', '', array(
      'cards' => $cards,
      'from' => $from
    ));
  }

  function getFirstCardInDeck()
  {
    $card = $this->cards->getCardOnTop(self::DECK);

    /*
     * In case the deck is empty, move all discarded card except the last one
     * to the deck and shuffle it
     */
    if (!$card) {
      $last_discarded_card = $this->cards->getCardOnTop(self::TMP_DISCARD);

      $this->cards->moveAllCardsInLocation(self::TMP_DISCARD, self::DECK);
      $this->cards->moveAllCardsInLocation(self::DISCARD, self::DECK);
      $this->cards->shuffle(self::DECK);

      $this->cards->moveCard($last_discarded_card['id'], self::TMP_DISCARD);

      $this->notifyAllPlayers(
        'message',
        clienttranslate('Shuffling discarded cards and refilling the deck'),
        array()
      );

      return $this->getFirstCardInDeck();
    }

    return $card;
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

    if (!$card_id) {
      $card = $this->getFirstCardInDeck();
    } else {
      $card = $this->cards->getCard($card_id);
    }

    if (!$card)
      throw new feException(clienttranslate("This card does not exists"));

    if ($card['location'] != self::DECK && $card['location'] != self::TMP_DISCARD)
      throw new feException(clienttranslate("This card cannot be taken"));

    $current_player_id = $this->getActivePlayerId();
    $this->cards->moveCard($card['id'], self::HAND, $current_player_id);

    if ($card['location'] == self::DECK) {
      $message = clienttranslate('${player_name} takes a card from the deck');
    } else {
      $message = clienttranslate('${player_name} takes a card from the discard');
    }

    $this->notifyAllPlayers(
      'message',
      $message,
      array(
        'player_name' => $this->getActivePlayerName(),
      )
    );

    $this->notifyPlayer($current_player_id, 'cardTaken', '', array(
      'card' => $card,
      'from' => $card['location'],
      'to_player_id' => $current_player_id
    ));

    $this->notifyAllPlayers('cardTaken', '', array(
      'card' => null,
      'from' => $card['location'],
      'to_player_id' => $current_player_id
    ));
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
      throw new feException(clienttranslate("This is not your turn."));
    }
  }

  function getPlayerCards($player_id)
  {
    return $this->cards->getCardsInLocation(self::HAND, $player_id);
  }

  function getPlayerCardsCount($player_id)
  {
    return count($this->getPlayerCards($player_id));
  }

  function getHandPointsForPlayerId($player_id)
  {
    $cards = $this->getPlayerCards($player_id);
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

  function notifyPlayersAboutScores($showedCards, $current_player_id)
  {
    $this->notifyAllPlayers('updateScore', '', array(
      'players' => $this->getPlayersData(),
      'cards' => $showedCards,
      'current_player_id' => $current_player_id
    ));
  }

  function notifyAllPlayersAboutCurrentPlayerCardsCount()
  {
    $player_id = $this->getActivePlayerId();
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
}
