{OVERALL_GAME_HEADER}

<div id="table">
  <div id="card_mat">
      <div id="deck"></div>
      <div id="discard"></div>
    <div id="drawed_cards"></div>
  </div>
</div>

<div id="player_hand" class="whiteblock"></div>

<script type="text/javascript">
var jstpl_player_board = ' \
  <div id="panel_p${id}"> \
    <span id="cards_count_p${id}"> \
    </span> \
    <i class="fa fa-hand-stop-o"></i> \
  </div> \
';

</script>

{OVERALL_GAME_FOOTER}
