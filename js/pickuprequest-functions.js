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

      function artPrintChecked() {
        var numitems = $("#edit-item-table tbody tr").length;

        var artPrintChecked = false;

        for (var i = numitems; i > 0; i--) {
          var id = 'edit-item-table' + '-' + i;
          var checkeditem = $('#' + id).is(':checked');

          var currentRow = $("#edit-item-table tbody tr");

          var name = currentRow.find("td:eq(0)").text(); // get current row 1st TD value
          var branch = currentRow.find("td:eq(1)").text(); // get current row 2nd TD
          var artPrint = currentRow.find("td:eq(2)").text(); // get current row 3rd TD
          if ( artPrint && checkeditem) {
            artPrintChecked =true;
          }
        }
        return artPrintChecked;
      }
      function lockerSelected() {
        pickupTypeSelectedText = $("#edit-pickup-type :selected").text();
        var lowercaseSelected = pickupTypeSelectedText.toLowerCase();
        if (lowercaseSelected.includes('locker')) {
          return true;
        }
        else {
          return false;
        }
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
        var numItemsSelected = checkedItems();
        if ((true == lockerSelected()) && (numItemsSelected > max_locker_items_check)) {
          // show the warning banner
          displayBanner('Please note, the ' + numItemsSelected + ' checked items may not fit in the selected locker pickup method. Any items that do not fit in the locker will be placed in the lobby', 'warning');
        }

        if ((true == lockerSelected()) && (true == artPrintChecked())) {
          // show the warning banner
          displayBanner('Please note, the art print selected cannot be picked up from a locker', 'warning');
        }

      }

      // EVENT HANDLERS 

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
        else {
          if (true == artPrintChecked() && true == lockerSelected()) {
            var cancelHolds = confirm('The selected art print will be placed in the lobby for pickup');
          }
        }
      });
    });
    }
  }
})(jQuery, Drupal);
