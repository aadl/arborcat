(function ($, Drupal) {
  Drupal.behaviors.pickupRequestBehavior = {
    attach: function (context, settings) {

    var max_locker_items_check = drupalSettings.arborcat.max_locker_items_check; 

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
        // make the view scroll so the warning is actually visible to the user
        $('.status-messages')[0].scrollIntoView({ behavior: 'smooth' });

      }

      function selectedItemsCheck() {
        $('.status-messages').remove();
        selectedText = $("#edit-pickup-type :selected").text();
        var lowercaseSelected = selectedText.toLowerCase();
        var numItemsSelected = checkedItems();
        if (lowercaseSelected.includes('locker') && numItemsSelected > max_locker_items_check) {
          // show the warning banner
          displayBanner('Please note, the ' + numItemsSelected + ' checked items may not fit in the selected locker pickup method. Any items that do not fit in the locker will be placed in the lobby', 'warning');
        }
      }

      // EVENT HANDLERS 
      $(document).ready(function () {
        var pickupDateOptions = $('#test').data("pickupDateOptions");
        var pickupLocations = $('#test').data("pickupLocations");
        console.log('### pickupDateOptions: ' + pickupDateOptions + "\n");
        console.log('### pickupLocations: ' + pickupLocations + "\n");
      });
      
      $('#edit-pickup-type').change(function () {
        selectedItemsCheck();
       });


       $('#edit-item-table thead tr').click(function (e) {
        selectedItemsCheck();
      });

      $('#edit-item-table tbody tr').click(function(e) {
        selectedItemsCheck();
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
    }
  }
})(jQuery, Drupal);
