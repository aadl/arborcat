(function ($, Drupal) {

  $(function () {
    // INITIALIZATION/SETUP
  

    // helper methods
    function checkedItems() {
      var numitems = $("#edit-item-table tbody tr").length;
      var numchecked = 0;
      for (var i = numitems; i > 0; i--) {
        var id = 'edit-item-table' + '-' + i;
        var checkeditem = $('#' + id).is(':checked');
        numchecked += (checkeditem) ? 1 : 0;
      }
      return numchecked;
    }
   
    function displayBanner(bannerText) {
      // build the banner 
      var msgWrapper = $(document.createElement('div'));
      msgWrapper.addClass('status-messages')
        .attr({ 'role': 'contentinfo', 'aria-label': 'Warning message' });
      var statusMsg = $(document.createElement('div'));
      statusMsg.addClass('warning')
        .html(bannerText);
      msgWrapper.append('<h2 class="visually-hidden">Warning Message</h2>')
        .append(statusMsg);
      $(msgWrapper).insertBefore('.outer-wrapper[role="main"]');
    }

    // EVENT HANDLERS
    // check for locker pickup method and greater than 6 items.
    $('#edit-pickup-type').change(function () {
      $('.status-messages').remove();

      selectedText = $("#edit-pickup-type :selected").text();
      var lowercaseSelected = selectedText.toLowerCase();
      var numItemsSelected = checkedItems();
      if (lowercaseSelected.includes('locker') && numItemsSelected > 6) {
        // show the warning banner
        displayBanner('Please note, the ' + numItemsSelected + ' checked items may not fit in the selected locker pickup method. Any items that do not fit in the locker will be placed in the lobby', 'warning');
      }
    });

    // toggle checkbox status on row with click
    $('#edit-item-table tbody tr').click(function(e) {
      if (e.target.nodeName != 'INPUT') {
        var checkBox = $(this).find('input[type=checkbox]');
        var checkStatus = checkBox.prop('checked');
        checkBox.prop('checked', !checkStatus);
      }
    });

    // give confirmation before canceling requests
    $('#edit-submit').click(function() {
      if ($(this).val() == 'Cancel selected requests') {
        var cancelHolds = confirm('Once the request is canceled, you will be removed from the waitlist');
        if (!cancelHolds) {
          return false;
        }
      }
    });
  });

})(jQuery, Drupal);
