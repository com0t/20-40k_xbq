(function ($, api) {
  var customizePrefix = '_customize-input-';
  var themePrefix = 'ppw_customize_presets_settings';
  var defaultShowLogo = null;
  var defaultData = {
    'default1': {
	  '#customize-control-ppwp_pro_form_instructions_background_color_control input.wp-color-picker' : ''
	},
	'default2': {
	  '#customize-control-ppwp_pro_form_instructions_background_color_control input.wp-color-picker' : ''
	},
	'default3': {
	  '#customize-control-ppwp_pro_form_instructions_background_color_control input.wp-color-picker' : ''
	},
  };

  // Editor control.
  $(document).ready(function($) {
	$('textarea.wp-editor-area').each(function () {
	  const $this = $(this),
		id = $this.attr('id'),
		$input = $('input[data-customize-setting-link="' + id + '"]');

	  $this.css('visibility', 'visible').on('keyup', function () {
		$input.val($this.val()).trigger('change');
	  });

	  if (tinyMCE.get(id)) {
		const editor = tinyMCE.get(id);
		editor.on('change', function (e) {
		  editor.save();
		  $input.val(editor.getContent()).trigger('change');
		});
	  } else {
		tinyMCE.init({
		  menubar: false,
		  selector: '#' + id,
		  setup: function (editor) {
			const $wraps = $this.closest('.wp-core-ui.wp-editor-wrap');
			if ($wraps.length > 0) {
			  const $wrap = $wraps[0];
			  $($wrap).addClass('tmce-active').removeClass('html-active');
			}

			editor.on('change', function (e) {
			  editor.save();
			  $input.val(editor.getContent()).trigger('change');
			});
		  }
		});
	  }
	});

	$('.customize-control-ppw-presets input[type="radio"]').on('change', function () {
	  var theme = $(this).val();
	  Object.keys(defaultData).forEach(function(theme) {
	    if ( $('#' + themePrefix + theme).is(':checked') ) {
	      	var themeData = defaultData[theme];
			Object.keys(themeData).forEach(function(themeKey){
			  changeInput(themeKey, themeData[themeKey], 'change');
			});
		}
	  });
	  var checkbox_values = $(this)
		.parents('.customize-control')
		.find('input[type="radio"]:checked')
		.val();
	  $(this)
		.parents('.customize-control')
		.find('input[type="hidden"]')
		.val(checkbox_values)
		.delay(500)
		.trigger('change');
	  $logo = $('#toggle-ppwp_pro_logo_disable_control');
	  if (defaultShowLogo !== null) {
		defaultShowLogo = $logo.is(':checked');
	  }
	  if ( 'default0' !== theme && $logo.length > 0) {
		$logo.prop('checked', true).trigger('input');
	  } else {
		$logo.prop('checked', defaultShowLogo).trigger('input');
	  }
	});

	function changeInput( key, value, type = 'input' ) {
	  $(key).val(value).delay(500).trigger(type);
	}

  });


})(jQuery, wp.customize);
