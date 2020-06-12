// (function ($, Drupal, drupalSettings) {
//   console.log("pickupRequest-functions.js - pickupRequest-functions.js ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED ENTERED\n");
//   Drupal.behaviors.toolbookingBehavior = {
//     attach: function (context, settings) {

//       console.log(">>> pickupRequest-functions.js - pickupRequest-functions.js ENTERED\n");


//     }
//   }
// })(jQuery, Drupal, drupalSettings);

console.log(">>> \n");

$(function () {
  console.log(">>> >>>\n");

  $('.edit-pickup-time input').change(function (e) {
    console.log(">>> edit-pickup-time.change\n");
  });

  $('#edit-pickup-time').click(function () {
    console.log(">>> edit-pickup-time CLICK \n");
    $('html, body').animate({
      scrollTop: $('#jump-link').offset().top - 40
    }, 0);
  });
});
