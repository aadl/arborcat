(function ($, Drupal) {

  $(function() {
    // ajax save to database
    $('.bisac-assign input').change(function(e) {
      var bisacForm = $(this).parents('.bisac-assign');
      setTimeout(function() {
        $.ajax({
          url: '/bisac-assign/update/' + bisacForm.attr('data-id') + '?callnum=' + encodeURIComponent(bisacForm.find('.bisac-callnum').val()) + '&order=' + encodeURIComponent(bisacForm.find('.bisac-shelf-order').val()),
          success: function(result) {
            console.log('Saved');
            bisacForm.find('p').html('Saved &#10004;').addClass('success-text').removeClass('display-none');
          }
        });
      }, 3000);
    });

    // get width of each th element and make sure this value sticks when changed to fixed positioning
    // var cURL = window.location.href;
    // var splitURL = cURL.split('/');
    // if (splitURL[4] == 'view' || splitURL[6] == 'edit') {
    //   tableHead = $('#bisac-th');
    //   thPosition = tableHead.offset().top;

    //   window.onscroll = function() {
    //     var currentPosition = $(window).scrollTop();
    //     if (currentPosition >= thPosition) {
    //       resizeTh();
    //       tableHead.css({'position': 'fixed', 'top': 0});
    //     }
    //     if (currentPosition < thPosition) {
    //       tableHead.css('position', 'static');
    //     }
    //   };
    //   $(window).resize(function() {
    //     resizeTh();
    //   });
    // }

    // function resizeTh() {
    //   var widths = [];
    //   $.each($('#bisac-table tr:nth-child(1)').children(), function(k, v) {
    //     widths.push($(v).width());
    //   });
    //   $.each(tableHead.children(), function(k, v) {
    //     v.width = widths[k];
    //     $(v).css('padding-right', '.5em');
    //   });
    // }
    
    //fix jump-to link to account for sticky thead
    $('#jump-link-btn').click(function() {
      $('html, body').animate({
        scrollTop: $('#jump-link').offset().top - 40
      }, 0);
    });

    //autopopulate shelf order with either author or series
    $('.bisac-auto-author, .bisac-auto-series').click(function(e) {
      var currentBtn = $(this);
      var bisacForm = currentBtn.parents('.bisac-assign');
      var shelfOrder = currentBtn.siblings().find('.bisac-shelf-order');
      if (currentBtn.val() == 'Use author') {
        var auth = currentBtn.parents('.bisac-form').siblings('.bisac-author').html().replace('.', '').split(',');
        if (auth.length > 1) {
          auth = auth[0] +','+ auth[1];
          shelfOrder.val(auth);
        }
        else {
          alert('Author does not have a value in this row');
        }
      }
      else {
        var series = currentBtn.parents('.bisac-form').siblings('.bisac-series').html().replace('.', '').split(';');
        if (series != '') {
          shelfOrder.val(series[0].replace(/\s\s+/g, ' '));
        }
        else {
          alert('Series does not have a value in this row');
        }
      }
      $.ajax({
        url: '/bisac-assign/update/' + bisacForm.attr('data-id') + '?callnum=' + encodeURIComponent(bisacForm.find('.bisac-callnum').val()) + '&order=' + encodeURIComponent(shelfOrder.val()),
        success: function(result) {
          console.log('Saved');
          bisacForm.find('p').html('Saved &#10004;').addClass('success-text').removeClass('display-none');
        }
      });
      e.preventDefault();
    });

    $('#edit-fixer-auto-author, #edit-fixer-auto-series').click(function(e) {
      var auth = $('#fixer-author').html().split(':');
      var series = $('#fixer-series').html().split(':');
      if ($(this).val() == 'Use author' && auth[1] != ' None') {
        $('#edit-shelf-order').val($.trim(auth[1]));
      }
      else if ($(this).val() == 'Use series' && series[1] != ' None') {
        $('#edit-shelf-order').val($.trim(series[1]));
      }
      else {
        alert('No value found; will not autopopulate');
      }
      e.preventDefault();
    });

    //async update and delete for BISAC schedule
    $('.bisac-confirm-update').click(function(e) {
      var currentBtn = $(this);
      console.log('/bisac-assign/schedule/update/' + currentBtn.parents('.bisac-edit-schedule-form').attr('data-id') + '?callnum=' + encodeURIComponent(currentBtn.siblings('.form-item').find('input.callnum-update').val()));
      $.ajax({
        url: '/bisac-assign/schedule/update/' + currentBtn.parents('.bisac-edit-schedule-form').attr('data-id') + '?callnum=' + encodeURIComponent(currentBtn.siblings('.form-item').find('input.callnum-update').val()),
      });
      currentBtn.val('Saved!');
      e.preventDefault();
    });
    $('.bisac-confirm-delete').click(function(e) {
      var confirmDelete = confirm('Are you sure you want to delete this callnum?');
      if (confirmDelete) {
        var currentParent = $(this).parents('.bisac-edit-schedule-form');
        $.ajax({
          url: '/bisac-assign/schedule/delete/' + currentParent.attr('data-id'),
          success: function() {
            currentParent.fadeOut();
          }
        });
      }
      e.preventDefault();
    });

    // copy to clipboard functionality for viewing BISAC schedule
    $('#bisac-assign-schedule-view-copy-form').submit(function() {
      return false;
    });

    $('#edit-bisac-copy').click(function() {
      var copyStr = $('#edit-bisac-view').select();
      try {
        document.execCommand('copy');
      }
      catch (err) {
        alert('Error. Please use Cmd/Ctrl+C to copy');
      }
    });

    $('#edit-bisac-clear').click(function() {
      $('#edit-bisac-view').val('');
    });

    // assist in autocomplete searching by selecting material type
    $('#edit-callnum-type').change(function() {
      console.log('changed');
      console.log($(this).val());
      $('#edit-callnum-search').val($(this).val());
    });

    $('.loc-section').click(function(e) {
      $(this).parent().children('.toggle-display').toggleClass('no-display')
      e.preventDefault();
    });

  });

})(jQuery, Drupal);
