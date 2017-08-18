(function ($, Drupal) {
  Drupal.behaviors.checkoutsBehavior = {
    attach: function (context, settings) {
      $.ajax({
      type: 'GET',
      url: 'http://api.drupal.docker.localhost:8000/patron/checkouts',
      dataType: 'json',
      success: function (data) {
          $.each(data, function(index, element) {
            $("#user-checkouts").append($('<li>').text(element.format + ": " + element.title + " by " + element.author));
          });
      }
      });
    }
  };
})(jQuery, Drupal);
