define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
  "ebg/stock",
], function (dojo, declare) {
  return declare("bgagame.guibole", ebg.core.gamegui, {
    constructor: function () {
      this.player_hand = null;
      this.discard = null;
      this.deck = null;
      this.played_cards = null;

      this.cardwidth = 70;
      this.cardheight = 96;

      this.card_value_by_id = {};
    },

    setup: function (gamedatas) {
      this.player_hand = new ebg.stock();
      this.player_hand.create(
        this,
        $("player_hand"),
        this.cardwidth,
        this.cardheight
      );
      this.player_hand.image_items_per_row = 13;
      this.player_hand.centerItems = true;
      this.player_hand.extraClasses = "guibole_card";

      this.deck = new ebg.stock();
      this.deck.create(this, $("deck"), this.cardwidth, this.cardheight);
      this.deck.image_items_per_row = 1;
      this.deck.centerItems = true;
      this.deck.extraClasses = "guibole_card";

      this.discard = new ebg.stock();
      this.discard.create(this, $("discard"), this.cardwidth, this.cardheight);
      this.discard.image_items_per_row = 13;
      this.discard.centerItems = true;
      this.discard.extraClasses = "guibole_card";

      this.played_cards = new ebg.stock();
      this.played_cards.create(
        this,
        $("played_cards"),
        this.cardwidth,
        this.cardheight
      );
      this.played_cards.image_items_per_row = 13;
      this.played_cards.centerItems = true;
      this.played_cards.extraClasses = "guibole_card";

      dojo.connect(
        this.player_hand,
        "onChangeSelection",
        this,
        "playerHandSelectionChanged"
      );

      dojo.connect(this.deck, "onChangeSelection", this, "deckSelected");
      dojo.connect(
        this.discard,
        "onChangeSelection",
        this,
        "discardedCardsSelected"
      );

      // Create cards types:
      for (var color = 1; color <= 4; color++) {
        // K, Q, J, 10, ..., 2, A
        for (var value = 13; value >= 1; value--) {
          // Build card type id
          var card_position = this.getCardPosition(color, value);

          this.player_hand.addItemType(
            card_position,
            value,
            g_gamethemeurl + "img/cards.jpg",
            card_position
          );
          this.discard.addItemType(
            card_position,
            value,
            g_gamethemeurl + "img/cards.jpg",
            card_position
          );
          this.played_cards.addItemType(
            card_position,
            value,
            g_gamethemeurl + "img/cards.jpg",
            card_position
          );
          this.deck.addItemType(
            card_position,
            value,
            g_gamethemeurl + "img/card_back.jpg",
            card_position
          );

          if (value === 1 && color === 1) {
            this.deck.addToStockWithId(this.getCardPosition(color, value), 404);
          }
        }
      }

      // Cards in player's hand
      this.setHand(this.getObjectsFromDatabaseObject(gamedatas.hand));

      // Discarded cards
      this.setDiscardedCards(
        this.getObjectsFromDatabaseObject(gamedatas.discard),
        null
      );

      // Played cards
      this.setPlayedCards(
        this.getObjectsFromDatabaseObject(gamedatas.played_cards)
      );

      this.played_cards.setSelectionMode(0);

      this.setupNotifications();

      this.cards_in_hand = {};
      for (var player_id in gamedatas.players) {
        var player = gamedatas.players[player_id];

        // Setting up players boards if needed
        var player_board_div = $("player_board_" + player_id);
        dojo.place(
          this.format_block("jstpl_player_board", player),
          player_board_div
        );

        // set number of cards in hand for each player
        this.cards_in_hand[player_id] = new ebg.counter();
        this.cards_in_hand[player_id].create("cards_count_p" + player_id);
        this.cards_in_hand[player_id].setValue(player.cards_count);

        this.addTooltip(
          "panel_p" + player_id,
          _("Number of cards in player's hand"),
          ""
        );
      }
    },

    getCardPosition: function (color, value) {
      return color * 13 - value;
    },

    onEnteringState: function (stateName, args) {
      this.discard.setSelectionMode(0);
      this.deck.setSelectionMode(0);

      switch (stateName) {
        case "playCardsOrEndRoundState":
          // TODO useless tooltip
          this.addTooltip("player_hand", _("Cards in my hand"), "");
          break;
        case "drawCardState":
          if (this.isCurrentPlayerActive()) {
            this.discard.setSelectionMode(1);
            this.deck.setSelectionMode(1);
          }
          break;
      }
    },

    onLeavingState: function (stateName) {
      switch (stateName) {
        case "dummmy":
          break;
      }
    },

    onUpdateActionButtons: function (stateName, args) {
      if (this.isCurrentPlayerActive()) {
        switch (stateName) {
          case "playCardsOrEndRoundState":
            this.addActionButton(
              "playCards_button",
              _("Play selected cards"),
              "playCards"
            );
            this.addActionButton("endRound_button", _("Guibole"), "endRound");
            break;
          case "drawCardState":
            this.addActionButton("drawCard_button", _("Confirm"), "drawCard");
            break;
        }
      }
    },

    setupNotifications: function () {
      console.debug("Entering setupNotifications");

      dojo.subscribe("newHand", this, "newHand");
      dojo.subscribe("discardedCards", this, "discardedCards");
      dojo.subscribe("playedCards", this, "playedCards");
      dojo.subscribe("cardTaken", this, "cardTaken");
      dojo.subscribe("updateScore", this, "updateScore");
      this.notifqueue.setSynchronous("updateScore", 5000);

      dojo.subscribe(
        "currentPlayerCardsCountUpdate",
        this,
        "currentPlayerCardsCountUpdate"
      );

      console.debug("Leaving setupNotifications");
    },

    getObjectsFromDatabaseObject(object) {
      const objects = [];
      for (var i in object) objects.push(object[i]);
      return objects;
    },

    newHand: function (notification) {
      console.debug("Entering newHand");

      this.setHand(this.getObjectsFromDatabaseObject(notification.args.cards));

      console.debug("Leaving newHand");
    },

    setHand: function (cards) {
      this.player_hand.removeAll();

      for (const card of cards) {
        var color = card.type;
        var value = card.type_arg;
        this.card_value_by_id[card.id] = value;

        this.player_hand.addToStockWithId(
          this.getCardPosition(color, value),
          card.id
        );
      }
    },

    discardedCards: function (notification) {
      this.setDiscardedCards(
        this.getObjectsFromDatabaseObject(notification.args.cards),
        notification.args.from
      );
    },

    setDiscardedCards: function (cards, from) {
      console.debug("Entering setDiscardedCards");

      for (const card of this.discard.getAllItems()) {
        this.discard.removeFromStockById(card.id);
      }

      for (const card of cards) {
        var color = card.type;
        var value = card.type_arg;

        if (from === "played_cards") {
          this.discard.addToStockWithId(
            this.getCardPosition(color, value),
            card.id,
            from
          );
          this.played_cards.removeFromStockById(card.id);
        } else {
          this.discard.addToStockWithId(
            this.getCardPosition(color, value),
            card.id
          );
        }
      }
      console.debug("Leaving setDiscardedCards");
    },

    playedCards: function (notification) {
      this.setPlayedCards(
        this.getObjectsFromDatabaseObject(notification.args.cards),
        notification.args.player_id
      );
    },

    setPlayedCards: function (cards, player_id) {
      this.played_cards.removeAll();

      for (const card of cards) {
        var color = card.type;
        var value = card.type_arg;

        if (!player_id) {
          this.played_cards.addToStockWithId(
            this.getCardPosition(color, value),
            card.id
          );
        } else if (this.player_id === parseInt(player_id)) {
          this.played_cards.addToStockWithId(
            this.getCardPosition(color, value),
            card.id,
            "player_hand_item_" + card.id
          );
          this.player_hand.removeFromStockById(card.id);
        } else {
          this.played_cards.addToStockWithId(
            this.getCardPosition(color, value),
            card.id,
            "player_board_" + player_id
          );
        }
      }
    },

    cardTaken: function (notification) {
      card = notification.args.card;
      from = notification.args.from;
      to_player_id = parseInt(notification.args.to_player_id);

      if (card) {
        if (from === "deck") {
          this.deck.addToStockWithId(
            this.getCardPosition(card.type, card.type_arg),
            card.id
          );
          this.player_hand.addToStockWithId(
            this.getCardPosition(card.type, card.type_arg),
            card.id,
            "deck"
          );
          this.deck.removeFromStockById(card.id);
        } else {
          this.player_hand.addToStockWithId(
            this.getCardPosition(card.type, card.type_arg),
            card.id,
            "discard_item_" + card.id
          );
        }
        this.card_value_by_id[card.id] = card.type_arg;
      } else if (to_player_id != this.player_id) {
        if (from === "deck") {
          this.deck.addToStockWithId(this.getCardPosition(1, 1), 405);
          this.deck.removeFromStockById(405, "player_board_" + to_player_id);
        } else {
          const card = this.discard.getAllItems()[0];
          const card_div = "discard_item_" + card.id;
          this.placeOnObject(card_div, "discard");
          this.slideToObject(card_div, "player_board_" + to_player_id).play();
          this.discard.removeFromStockById(card.id);
        }
      }
    },

    updateScore: function (notification) {
      console.debug("Entering updateScore");

      this.setPlayedCards(
        this.getObjectsFromDatabaseObject(notification.args.cards),
        notification.args.current_player_id
      );

      for (const player of this.getObjectsFromDatabaseObject(
        notification.args.players
      )) {
        this.scoreCtrl[player.id].toValue(player.score);
        this.cards_in_hand[player.id].toValue(5);
      }

      console.debug("Leaving updateScore");
    },

    currentPlayerCardsCountUpdate: function (notification) {
      console.debug("Entering currentPlayerCardsCountUpdate");

      this.cards_in_hand[notification.args.player.id].toValue(
        notification.args.player.cards_count
      );

      console.debug("Leaving currentPlayerCardsCountUpdate");
    },

    playerHandSelectionChanged: function () {
      if (!this.doesCardsHaveSameValues(this.player_hand.getSelectedItems())) {
        this.showMessage(
          _("You can only play multiple card of same value"),
          "error"
        );
        return;
      }
    },

    deckSelected: function () {
      /*
       * Toggle selection of the discarded cards pile
       */
      var cards = this.deck.getSelectedItems();

      if (cards.length != 0) {
        this.discard.unselectAll();
      }
    },
    discardedCardsSelected: function () {
      /*
       * Toggle selection of the deck
       */
      var cards = this.discard.getSelectedItems();

      if (cards.length != 0) {
        this.deck.unselectAll();
      }
    },

    doesCardsHaveSameValues: function (cards) {
      const selected_card_values = [];

      for (const card of cards) {
        const value = this.card_value_by_id[card.id];

        if (!selected_card_values.length) {
          selected_card_values.push(value);
          continue;
        }

        if (!selected_card_values.includes(value)) {
          return false;
        }
      }

      return true;
    },

    playCards: function () {
      if (this.checkAction("playCardsOrEndRoundState", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      var cards = this.player_hand.getSelectedItems();

      if (cards.length === 0) {
        this.showMessage(_("You must select at least 1 card"), "error");
        return;
      }

      if (!this.doesCardsHaveSameValues(cards)) {
        this.showMessage(
          _("You can only play multiple card of same value"),
          "error"
        );
        return;
      }

      var card_ids = "";

      for (const card of cards) {
        card_ids += card.id + ";";
      }

      this.ajaxcall(
        "/guibole/guibole/playCards.html",
        {
          ids: card_ids,
          lock: true,
        },
        this,
        function (result) {},
        function (is_error) {}
      );

      this.player_hand.unselectAll();
    },

    drawCard: function () {
      if (this.checkAction("drawCardState", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      var card_id = null;
      if (this.deck.getSelectedItems().length === 1) {
        card_id = null;
      } else if (this.discard.getSelectedItems().length === 1) {
        card_id = this.discard.getSelectedItems()[0].id;
      } else {
        this.showMessage(_("You must take a card"), "error");
        return;
      }

      this.ajaxcall(
        "/guibole/guibole/drawCard.html",
        {
          id: card_id,
          lock: true,
        },
        this,
        function (result) {},
        function (is_error) {}
      );

      this.deck.unselectAll();
      this.discard.unselectAll();
    },

    endRound: function () {
      if (this.checkAction("playCardsOrEndRoundState", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      this.ajaxcall(
        "/guibole/guibole/endRound.html",
        {
          lock: true,
        },
        this,
        function (result) {},
        function (is_error) {}
      );
    },
  });
});
