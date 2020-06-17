// (function ($, Drupal, drupalSettings) {
//   console.log("pickupRequest-functions.js - pickupRequest-functions.js ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED\n");
//   Drupal.behaviors.toolbookingBehavior = {
//     attach: function (context, settings) {

//       console.log(">>> pickupRequest-functions.js - pickupRequest-functions.js ENTERED\n");


//     }
//   }
// })(jQuery, Drupal, drupalSettings);

(function ($, Drupal) {

  $(function () {
    console.log(">>> >>>\n");
    // INITIALIZATION/SETUP
    // Initially hide the pickup time item in the form as lobby selection is the default for any location
    $(".form-item-pickup-time").hide();

    // Hiding/Showing the pickup time is commented out for now and will probably be removed in favor of the 
    // Pickup method drop-down menu now containing entries for the lockers with the pickup time options.

    // $(document).on('change', '#edit-pickup-type', function () {
    //   var selectedValue = $("#edit-pickup-type option:selected").text();
    //   var lowercaseValue = selectedValue.toLowerCase();
    //   console.log(">>> #edit-pickup-type' - selectedValue = " + lowercaseValue + "\n");
    //   if (lowercaseValue.includes("locker")) {
    //     console.log(">>> #edit-pickup-type - selected a locker\n");
    //     $(".form-item-pickup-time").show();
    //   } else {
    //     console.log(">>> #edit-pickup-type - selected a lobby\n");
    //     $(".form-item-pickup-time").hide();
    //   }
    // });

    $('#edit-pickup-time').click(function () {
      console.log(">>> edit-pickup-time CLICK \n");
      // $('html, body').animate({
      //   scrollTop: $('#jump-link').offset().top - 40
      // }, 0);
    });

    // toggle checkbox status on row with click
    $('#edit-item-table tbody tr').click(function() {
      console.log('table click');
      var checkBox = $(this).find('input[type=checkbox]');
      var checkStatus = checkBox.prop('checked');
      checkBox.prop('checked', !checkStatus);
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
