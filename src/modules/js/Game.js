const [counter, stock] = await importDojoLibs(["ebg/counter", "ebg/stock"]);

class Game {
  constructor(bga) {
    this.bga = bga;

    this.player_hand = null;
    this.discard = null;
    this.deck = null;
    this.played_cards = null;

    this.cardwidth = 70;
    this.cardheight = 96;

    this.card_value_by_id = {};
    this.cards_in_hand = {};

    this.bga.states.register("PlayCardsOrEndRound", {
      onEnteringState: (args, isCurrentPlayerActive) => {
        this.bga.statusBar.removeActionButtons();
        this.discard.setSelectionMode(0);
        this.deck.setSelectionMode(0);
        this.bga.gameui.addTooltip("player_hand", _("Cards in my hand"), "");

        if (isCurrentPlayerActive) {
          this.bga.statusBar.addActionButton(_("Play selected cards"), () =>
            this.playCards(),
          );
          this.bga.statusBar.addActionButton(_("Guibole"), () =>
            this.endRound(),
          );
        }
      },
      onLeavingState: (_args, _isCurrentPlayerActive) => {
        this.bga.statusBar.removeActionButtons();
      },
    });

    this.bga.states.register("DrawCard", {
      onEnteringState: (args, isCurrentPlayerActive) => {
        this.bga.statusBar.removeActionButtons();
        if (isCurrentPlayerActive) {
          this.discard.setSelectionMode(1);
          this.deck.setSelectionMode(1);
          this.bga.statusBar.addActionButton(_("Confirm"), () =>
            this.drawCard(),
          );
        }
      },
      onLeavingState: (_args, _isCurrentPlayerActive) => {
        this.bga.statusBar.removeActionButtons();
        this.discard.setSelectionMode(0);
        this.deck.setSelectionMode(0);
      },
    });
  }

  setup(gamedatas) {
    this.bga.gameArea.getElement().insertAdjacentHTML(
      "beforeend",
      `
      <div id="table">
        <div id="card_mat">
          <div id="deck"><div id="deck_count"></div></div>
          <div id="discard"></div>
          <div id="played_cards"></div>
        </div>
      </div>
      <div id="player_hand" class="whiteblock"></div>
    `,
    );

    this.player_hand = new ebg.stock();
    this.player_hand.create(
      this.bga.gameui,
      document.getElementById("player_hand"),
      this.cardwidth,
      this.cardheight,
    );
    this.player_hand.image_items_per_row = 13;
    this.player_hand.centerItems = true;
    this.player_hand.extraClasses = "guibole_card";

    this.deck = new ebg.stock();
    this.deck.create(
      this.bga.gameui,
      document.getElementById("deck"),
      this.cardwidth,
      this.cardheight,
    );
    this.deck.image_items_per_row = 1;
    this.deck.centerItems = true;
    this.deck.extraClasses = "guibole_card";

    this.discard = new ebg.stock();
    this.discard.create(
      this.bga.gameui,
      document.getElementById("discard"),
      this.cardwidth,
      this.cardheight,
    );
    this.discard.image_items_per_row = 13;
    this.discard.centerItems = true;
    this.discard.extraClasses = "guibole_card";

    this.played_cards = new ebg.stock();
    this.played_cards.create(
      this.bga.gameui,
      document.getElementById("played_cards"),
      this.cardwidth,
      this.cardheight,
    );
    this.played_cards.image_items_per_row = 13;
    this.played_cards.centerItems = true;
    this.played_cards.extraClasses = "guibole_card";

    this.player_hand.onChangeSelection = (_, _item_id) =>
      this.playerHandSelectionChanged();
    this.deck.onChangeSelection = (_, _item_id) => this.deckSelected();
    this.discard.onChangeSelection = (_, _item_id) =>
      this.discardedCardsSelected();

    for (let color = 1; color <= 4; color++) {
      for (let value = 13; value >= 1; value--) {
        const card_position = this.getCardPosition(color, value);

        this.player_hand.addItemType(
          card_position,
          value,
          g_gamethemeurl + "img/cards.png",
          card_position,
        );
        this.discard.addItemType(
          card_position,
          value,
          g_gamethemeurl + "img/cards.png",
          card_position,
        );
        this.played_cards.addItemType(
          card_position,
          value,
          g_gamethemeurl + "img/cards.png",
          card_position,
        );
        this.deck.addItemType(
          card_position,
          value,
          g_gamethemeurl + "img/card_back.png",
          card_position,
        );

        if (value === 1 && color === 1) {
          this.deck.addToStockWithId(this.getCardPosition(color, value), 404);
          this.bga.gameui.attachToNewParent("deck_count", "deck_item_404");
          this.bga.gameui.placeOnObjectPos(
            "deck_count",
            "deck_item_404",
            25,
            23,
          );
        }
      }
    }

    this.setHand(this.getObjectsFromDatabaseObject(gamedatas.hand));
    this.setDiscardedCards(
      this.getObjectsFromDatabaseObject(gamedatas.discard),
      null,
    );
    this.setPlayedCards(
      this.getObjectsFromDatabaseObject(gamedatas.played_cards),
    );

    this.played_cards.setSelectionMode(0);

    for (const player_id in gamedatas.players) {
      const player = gamedatas.players[player_id];

      const player_board_div = this.bga.playerPanels.getElement(player_id);
      player_board_div.insertAdjacentHTML(
        "beforeend",
        `
        <div id="panel_p${player.id}">
          <span id="cards_count_p${player.id}"></span>
          <i class="fa fa-hand-stop-o"></i>
        </div>
      `,
      );

      this.cards_in_hand[player_id] = new ebg.counter();
      this.cards_in_hand[player_id].create("cards_count_p" + player_id);
      this.cards_in_hand[player_id].setValue(player.cards_count);

      this.bga.gameui.addTooltip(
        "panel_p" + player_id,
        _("Number of cards in player's hand"),
        "",
      );
    }

    const deck_count_el = document.getElementById("deck_count");
    deck_count_el.innerHTML = gamedatas.deck_count;
    deck_count_el.style.backgroundColor = "white";
    deck_count_el.style.textAlign = "center";
    deck_count_el.style.width = "20px";

    this.bga.notifications.setupPromiseNotifications();
  }

  getCardPosition(color, value) {
    return color * 13 - value;
  }

  getObjectsFromDatabaseObject(object) {
    const objects = [];
    for (const i in object) objects.push(object[i]);
    return objects;
  }

  notif_newHand(args) {
    this.setHand(this.getObjectsFromDatabaseObject(args.cards));
  }

  setHand(cards) {
    this.player_hand.removeAll();

    for (const card of cards) {
      const color = card.type;
      const value = card.type_arg;
      this.card_value_by_id[card.id] = value;
      this.player_hand.addToStockWithId(
        this.getCardPosition(color, value),
        card.id,
      );
    }
  }

  notif_discardedCards(args) {
    this.setDiscardedCards(
      this.getObjectsFromDatabaseObject(args.cards),
      args.from,
    );
  }

  setDiscardedCards(cards, from) {
    for (const card of this.discard.getAllItems()) {
      this.discard.removeFromStockById(card.id);
    }

    for (const card of cards) {
      const color = card.type;
      const value = card.type_arg;

      if (from === "played_cards") {
        this.discard.addToStockWithId(
          this.getCardPosition(color, value),
          card.id,
          from,
        );
        this.played_cards.removeFromStockById(card.id);
      } else {
        this.discard.addToStockWithId(
          this.getCardPosition(color, value),
          card.id,
        );
      }
    }
  }

  notif_playedCards(args) {
    this.setPlayedCards(
      this.getObjectsFromDatabaseObject(args.cards),
      args.player_id,
    );
  }

  setPlayedCards(cards, player_id) {
    this.played_cards.removeAll();

    for (const card of cards) {
      const color = card.type;
      const value = card.type_arg;

      if (!player_id) {
        this.played_cards.addToStockWithId(
          this.getCardPosition(color, value),
          card.id,
        );
      } else if (gameui.player_id === parseInt(player_id)) {
        this.played_cards.addToStockWithId(
          this.getCardPosition(color, value),
          card.id,
          "player_hand_item_" + card.id,
        );
        this.player_hand.removeFromStockById(card.id);
      } else {
        this.played_cards.addToStockWithId(
          this.getCardPosition(color, value),
          card.id,
          this.bga.playerPanels.getElement(player_id),
        );
      }
    }
  }

  notif_cardTaken(args) {
    const card = args.card;
    const from = args.from;
    const to_player_id = parseInt(args.to_player_id);

    if (card) {
      if (from === "deck") {
        this.deck.addToStockWithId(
          this.getCardPosition(card.type, card.type_arg),
          card.id,
        );
        this.player_hand.addToStockWithId(
          this.getCardPosition(card.type, card.type_arg),
          card.id,
          "deck",
        );
        this.deck.removeFromStockById(card.id);
      } else {
        this.player_hand.addToStockWithId(
          this.getCardPosition(card.type, card.type_arg),
          card.id,
          "discard_item_" + card.id,
        );
      }
      this.card_value_by_id[card.id] = card.type_arg;
    } else if (to_player_id !== gameui.player_id) {
      if (from === "deck") {
        this.deck.addToStockWithId(this.getCardPosition(1, 1), 405);
        this.deck.removeFromStockById(
          405,
          this.bga.playerPanels.getElement(to_player_id),
        );
      } else {
        const top_card = this.discard.getAllItems()[0];
        const card_div = "discard_item_" + top_card.id;
        this.bga.gameui.placeOnObject(card_div, "discard");
        this.bga.gameui
          .slideToObject(
            card_div,
            this.bga.playerPanels.getElement(to_player_id),
          )
          .play();
        this.discard.removeFromStockById(top_card.id);
      }
    }
  }

  notif_updateScore(args) {
    this.setPlayedCards(
      this.getObjectsFromDatabaseObject(args.cards),
      args.current_player_id,
    );

    for (const player of this.getObjectsFromDatabaseObject(
      args.players,
    )) {
      this.bga.playerPanels.getScoreCounter(player.id).toValue(player.score);
      this.cards_in_hand[player.id].toValue(5);
    }

    return new Promise((resolve) => setTimeout(resolve, 5000));
  }

  notif_deckCountUpdate(args) {
    document.getElementById("deck_count").innerHTML =
      args.deck_count;
  }

  notif_currentPlayerCardsCountUpdate(args) {
    this.cards_in_hand[args.player.id].toValue(
      args.player.cards_count,
    );
  }

  playerHandSelectionChanged() {
    if (!this.doesCardsHaveSameValues(this.player_hand.getSelectedItems())) {
      this.bga.dialogs.showMessage(
        _("You can only play multiple card of same value"),
        "error",
      );
    }
  }

  deckSelected() {
    const cards = this.deck.getSelectedItems();
    if (cards.length !== 0) {
      this.discard.unselectAll();
    }
  }

  discardedCardsSelected() {
    const cards = this.discard.getSelectedItems();
    if (cards.length !== 0) {
      this.deck.unselectAll();
    }
  }

  doesCardsHaveSameValues(cards) {
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
  }

  playCards() {
    const cards = this.player_hand.getSelectedItems();

    if (cards.length === 0) {
      this.bga.dialogs.showMessage(
        _("You must select at least 1 card"),
        "error",
      );
      return;
    }

    if (!this.doesCardsHaveSameValues(cards)) {
      this.bga.dialogs.showMessage(
        _("You can only play multiple card of same value"),
        "error",
      );
      return;
    }

    let card_ids = "";
    for (const card of cards) {
      card_ids += card.id + ";";
    }

    this.bga.actions.performAction("action_playCards", { ids: card_ids });
    this.player_hand.unselectAll();
  }

  drawCard() {
    let card_id = null;
    if (this.deck.getSelectedItems().length === 1) {
      card_id = null;
    } else if (this.discard.getSelectedItems().length === 1) {
      card_id = this.discard.getSelectedItems()[0].id;
    } else {
      this.bga.dialogs.showMessage(_("You must take a card"), "error");
      return;
    }

    this.bga.actions.performAction("action_drawCard", { id: card_id });
    this.deck.unselectAll();
    this.discard.unselectAll();
  }

  endRound() {
    this.bga.actions.performAction("action_endRound", {});
  }
}

export { Game };
