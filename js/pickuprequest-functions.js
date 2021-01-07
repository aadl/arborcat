(function ($, Drupal) {
  Drupal.behaviors.pickupRequestBehavior = {
    attach: function (context, settings) {
      $(document, context).once('pickupRequestBehavior').each(function () {
        var maxLockerItemsCheck = drupalSettings.arborcat.max_locker_items_check;
        var exclusionMarkerString = drupalSettings.arborcat.exclusion_marker_string;

        $(function () {
          // INITIALIZATION/SETUP
          var pickup_date_select_field = $("#edit-pickup-date");
          // check each of the option display strngs for each date to see whether the exclusionMarkerString has been appended to the end of the date.
          // If so, disable the menu item
          var options = $('#edit-pickup-date option');
          for (var index=0; index < options.length; index++) {
            var anOption = options[index];
            var optionOuterText = anOption.outerText;
            if (optionOuterText.includes(exclusionMarkerString)) {
              $(anOption).attr('disabled','disabled');
            }
          }

         function checkedItems() {
            var numItems = $("#edit-item-table tbody tr").length;
            var numChecked = 0;
            for (var i = numItems; i > 0; i--) {
              var id = 'edit-item-table' + '-' + i;
              var checkedItem = $('#' + id).is(':checked');
              numChecked += (checkedItem) ? 1 : 0;
            }
            return numchecked;
          }

          function artPrintorToolChecked() {
            var returnFlag = false;
            var numItems = $("#edit-item-table tbody tr").length;
            for (var i = numItems; i > 0; i--) {
              var id = 'edit-item-table' + '-' + i;
              var checkedItem = $('#' + id).is(':checked');
              var currentRow = $("#edit-item-table tbody tr");
              var artPrintOrTool = currentRow.find("td:eq(3)").text(); // get current row 4th column
              if (artPrintOrTool && checkedItem) {
                returnFlag = true;
              }
            }
            return returnFlag;
          }

          function lockerSelected() {
            pickupTypeSelectedText = $("#edit-pickup-type :selected").text();
            var lowercaseSelected = pickupTypeSelectedText.toLowerCase();
            if (lowercaseSelected.includes('locker')) {
              return true;
            } else {
              return false;
            }
          }

          function locationSelected() {
            var value = $("#edit-pickup-type :selected").val();
            return (value.length > 0) ? true : false;
          }

          function dateSelected() {
            var value = $("#edit-pickup-date :selected").val();
            return (value.length > 0) ? true : false;
          }

          function displayBanner(bannerText) {
            // build the banner 
            var msgWrapper = $(document.createElement('div'));
            msgWrapper.addClass('status-messages')
              .attr({
                'role': 'contentinfo',
                'aria-label': 'Warning message'
              });
            var statusMsg = $(document.createElement('div'));
            statusMsg.addClass('warning')
              .html(bannerText);
            msgWrapper.append('<h2 class="visually-hidden">Warning Message</h2>')
              .append(statusMsg);
            $(msgWrapper).insertBefore('.outer-wrapper[role="main"]');
            // make the view scroll so the warning is actually visible to the user
            $('.status-messages')[0].scrollIntoView({
              behavior: 'smooth'
            });

          }

          function selectedItemsCheck() {
            $('.status-messages').remove();
            var numItemsSelected = checkedItems();
            if ((true == lockerSelected()) && (numItemsSelected > maxLockerItemsCheck)) {
              // show the warning banner
              displayBanner('Please note, the ' + numItemsSelected + ' checked items may not fit in the selected locker pickup method. Any items that do not fit in the locker will be placed in the lobby', 'warning');
            }

            if ((true == lockerSelected()) && (true == artPrintorToolChecked())) {
              // show the warning banner
              displayBanner('Please note, the art print selected cannot be picked up from a locker', 'warning');
            }
          }

           function submitForm() {
            $('#submitting').addClass('loading').css('position', 'relative'); // Shows the loading spinner
            $('#edit-submit').attr('disabled', true);
            $('#edit-submit').parents('form').submit();
          }

           // --------------------------------- EVENT HANDLERS --------------------------------- 

          $('#edit-pickup-type').change(function () {
            selectedItemsCheck();
          });


          $('#edit-item-table thead tr').click(function (e) {
            selectedItemsCheck();
          });

          $('#edit-item-table tbody tr').click(function (e) {
            selectedItemsCheck();
          });

          // give confirmation before canceling requests
          $('#edit-submit').click(function () {
            buttonName = $(this).val();
            // --- Handle Cancel pickup requests
            if (buttonName.startsWith('Cancel'))  {
              var cancelHolds = confirm('Once the request is canceled, you will be removed from the waitlist');
              submitForm();
            }
            // --- Handle Schedule pickup requests
            if (buttonName.startsWith('Schedule')) {
              // do validation on location, date and that items are checked in the list to be scheduled for pickup
              if (true == locationSelected() && true == dateSelected() && checkedItems() > 0) {
                submitForm();
              }
              else {
                if (0 == checkedItems()) {
                  displayBanner('At least one item must be checked to make a pickup appointment', 'warning');
                }
              } 
            }

            return false;
          });
        });
      });
    }
  }
})(jQuery, Drupal);
