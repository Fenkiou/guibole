define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
  "ebg/stock",
], function (dojo, declare) {
  return declare("bgagame.guibole", ebg.core.gamegui, {
    constructor: function () {
      this.playerHand = null;
      this.discard = null;
      this.deck = null;
      this.drawed_cards = null;

      this.cardwidth = 70;
      this.cardheight = 96;
    },

    setup: function (gamedatas) {
      this.playerHand = new ebg.stock();
      this.playerHand.create(
        this,
        $("player_hand"),
        this.cardwidth,
        this.cardheight
      );
      this.playerHand.image_items_per_row = 13;
      this.playerHand.centerItems = true;

      this.deck = new ebg.stock();
      this.deck.create(this, $("deck"), this.cardwidth, this.cardheight);
      this.deck.image_items_per_row = 1;
      this.deck.centerItems = true;

      this.discard = new ebg.stock();
      this.discard.create(this, $("discard"), this.cardwidth, this.cardheight);
      this.discard.image_items_per_row = 13;
      this.discard.centerItems = true;

      this.drawed_cards = new ebg.stock();
      this.drawed_cards.create(
        this,
        $("drawed_cards"),
        this.cardwidth,
        this.cardheight
      );
      this.drawed_cards.image_items_per_row = 13;
      this.drawed_cards.centerItems = true;

      dojo.connect(
        this.playerHand,
        "onChangeSelection",
        this,
        "playerHandSelectionChanged"
      );

      dojo.connect(this.deck, "onChangeSelection", this, "deckSelected");
      dojo.connect(
        this.discard,
        "onChangeSelection",
        this,
        "drawedCardsSelected"
      );

      // Create cards types:
      for (var color = 1; color <= 4; color++) {
        // K, Q, J, 10, ..., 2, A
        for (var value = 13; value >= 1; value--) {
          // Build card type id
          var card_type_id = this.getCardUniqueId(color, value);

          this.playerHand.addItemType(
            card_type_id,
            card_type_id,
            g_gamethemeurl + "img/cards.jpg",
            card_type_id
          );
          this.deck.addItemType(
            card_type_id,
            card_type_id,
            g_gamethemeurl + "img/card_back.jpg",
            card_type_id
          );
          this.discard.addItemType(
            card_type_id,
            card_type_id,
            g_gamethemeurl + "img/cards.jpg",
            card_type_id
          );
          this.drawed_cards.addItemType(
            card_type_id,
            card_type_id,
            g_gamethemeurl + "img/cards.jpg",
            card_type_id
          );
        }
      }

      // Cards in player's hand
      for (var i in this.gamedatas.hand) {
        var card = this.gamedatas.hand[i];
        var color = card.type;
        var value = card.type_arg;
        this.playerHand.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }

      for (var i in this.gamedatas.discard) {
        var card = this.gamedatas.discard[i];
        var color = card.type;
        var value = card.type_arg;

        this.discard.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }

      for (var i in this.gamedatas.drawed_cards) {
        var card = this.gamedatas.drawed_cards[i];
        var color = card.type;
        var value = card.type_arg;

        this.drawed_cards.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }

      var card = this.gamedatas.first_card_in_deck;
      var color = card.type;
      var value = card.type_arg;

      this.deck.addToStockWithId(this.getCardUniqueId(color, value), card.id);

      this.discard.setSelectionMode(1);
      this.deck.setSelectionMode(1);
      this.drawed_cards.setSelectionMode(0);

      this.setupNotifications();
    },

    onEnteringState: function (stateName, args) {
      switch (stateName) {
        case "playerTurn":
          this.addTooltip("player_hand", _("Cards in my hand"), "");
          break;
        case "playedCard":
          // TODO tooltip on the deck and pile
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
          case "playerTurn":
            this.addActionButton(
              "playCards_button",
              _("Play selected cards"),
              "playCards"
            );
            this.addActionButton(
              "showCards_button",
              _("Show cards"),
              "showCards"
            );
            break;
          case "playedCards":
            this.addActionButton("endTurn_button", _("End turn"), "endTurn");
            break;
        }
      }
    },

    setupNotifications: function () {
      dojo.subscribe("newHand", this, "newHand");
      dojo.subscribe("discardedCards", this, "discardedCards");
      dojo.subscribe("drawedCards", this, "drawedCards");
      dojo.subscribe("setFirstCardInDeck", this, "setFirstCardInDeck");
    },

    newHand: function (notification) {
      this.playerHand.removeAll();

      for (var i in notification.args.cards) {
        var card = notification.args.cards[i];
        var color = card.type;
        var value = card.type_arg;
        this.playerHand.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }
    },

    discardedCards: function (notification) {
      this.discard.removeAll();

      for (var i in notification.args.cards) {
        var card = notification.args.cards[i];
        var color = card.type;
        var value = card.type_arg;
        this.discard.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }
    },

    drawedCards: function (notification) {
      this.drawed_cards.removeAll();

      for (var i in notification.args.cards) {
        var card = notification.args.cards[i];
        var color = card.type;
        var value = card.type_arg;
        this.drawed_cards.addToStockWithId(
          this.getCardUniqueId(color, value),
          card.id
        );
      }
    },

    setFirstCardInDeck: function (notification) {
      this.deck.removeAll();

      var card = notification.args.card;
      var color = card.type;
      var value = card.type_arg;
      this.deck.addToStockWithId(this.getCardUniqueId(color, value), card.id);
    },

    getCardUniqueId: function (color, value) {
      return (color - 1) * 13 + (value - 2);
    },

    playerHandSelectionChanged: function () {
      // TODO check for wrong selection
      // or prevent wrong selection after first select
    },
    deckSelected: function () {
      /*
       * Toggle selection of the drawed cards pile
       */
      var cards = this.deck.getSelectedItems();

      if (cards.length != 0) {
        this.discard.unselectAll();
      }
    },
    drawedCardsSelected: function () {
      /*
       * Toggle selection of the deck
       */
      var cards = this.discard.getSelectedItems();

      if (cards.length != 0) {
        this.deck.unselectAll();
      }
    },

    playCards: function () {
      var cards = this.playerHand.getSelectedItems();

      if (cards.length === 0) {
        this.showMessage(_("You must select at least 1 card"), "error");
        return;
      }

      if (this.checkAction("playerTurn", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      var card_ids = "";

      for (var i in cards) {
        card_ids += cards[i].id + ";";
      }

      console.log("played", card_ids);
      // TODO: Checks cards are have same value

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

      this.playerHand.unselectAll();
      this.deck.unselectAll();
      this.discard.unselectAll();
    },

    endTurn: function () {
      if (this.checkAction("playedCards", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      var card = null;
      if (this.deck.getSelectedItems().length === 1) {
        card = this.deck.getSelectedItems()[0];
      } else if (this.discard.getSelectedItems().length === 1) {
        card = this.discard.getSelectedItems()[0];
      } else {
        this.showMessage(_("You must take a card"), "error");
        return;
      }

      this.ajaxcall(
        "/guibole/guibole/endTurn.html",
        {
          id: card.id,
          lock: true,
        },
        this,
        function (result) {},
        function (is_error) {}
      );

      this.deck.unselectAll();
      this.discard.unselectAll();
    },

    showCards: function () {
      if (this.checkAction("playerTurn", false)) {
        this.showMessage(_("Not your turn"), "error");
        return;
      }

      this.ajaxcall(
        "/guibole/guibole/showCards.html",
        {},
        this,
        function (result) {},
        function (is_error) {}
      );
    },
  });
});
