"use strict";
class MODULESEDITOR {

    #configData = null
    #testData = {}
    #moduleSettings = null
    #dirty = false
    #eventName = null
    #settings = null
    #first = true
	#dialogFilters = []
    #events = []
	#errors = []

    constructor() {

    }

    #buildUI() {
        $.LoadingOverlay('show');

        $('[data-toggle="tooltip"]').tooltip();

        $('#modules-available').sortable('destroy');
        $('#modules-selected').sortable('destroy');
        $('#modules-available').empty();
        $('#modules-selected').empty();
       
        $.ajax({
            url: 'includes/moduleutil.php?request=ModuleBaseData',
            type: 'GET',
            dataType: 'json',
            cache: false,
            context: this
        }).done((result) => {
            this.#settings = result;

            this.#dirty = false;
            this.#updateToolbar();

            $.moduleeditor = {
                settings: this.#settings.settings
            };
            if (this.#first) {
                $('#module-editor-config').empty();
                for (let event in this.#settings.settings.events) {
                    $('#module-editor-config').append(new Option(this.#settings.settings.events[event], event));
                }

                if (this.#settings.tod !== undefined) {
                    this.#eventName = this.#settings.tod;
                    $('#module-editor-config option[value="' + this.#eventName + '"]').attr("selected", "selected");
                    $('#module-editor-config').data("current", this.#eventName);
                }
                this.#first = false;
            }
            this.#eventName = $("#module-editor-config option").filter(":selected").val();

            $.ajax({
                url: 'includes/moduleutil.php?request=Modules&event=' + this.#eventName,
                type: 'GET',
                dataType: 'json',
                cache: false,
                context: this
            }).done((result) => {
                this.#configData = result;

                if (this.#configData.restore) {
                    $('#module-editor-restore').show();
                } else {
                    $('#module-editor-restore').hide();
                }

                this.#addModules(this.#configData.available, '#modules-available')
                this.#addModules(this.#configData.selected, '#modules-selected')

				$('[data-toggle="popover"]').popover('destroy')				
				$('[data-toggle="popover"]').popover()

                $(document).on('click', '.moduleenabler', (event) =>{
                    let element = $(event.currentTarget);
                    let checked = $(element).prop('checked');
                    let moduleName = $(element).data('module');
                    let module = this.#findModuleData(moduleName);
                    module.data.enabled = checked;
                });

                if (result.corrupted) {
                    let message = 'The Flow configuration is corrupted. Please use the reset Flow button to revert the flow to the installation default';
                    if (this.#configData.restore) {
                        message = 'The Flow configuration is corrupted. Please use the reset Flow button to revert the flow to the installation default or the Restore button to restore the last good configuration';
                    }
                    bootbox.alert(message);
                }
                this.#updateToolbar();

                $(document).on('click', '.module-add-button', (event) => {

					let id = $(event.target).data('module')
					$('#allskyloadimage').after($('#'+id));
					this.#moduleAdded($('#'+id))

                });

                $(document).on('click', '.module-delete-button', (event) => {
                    if (this.#dirty) {
                        bootbox.alert('Please save the current configuration before deleting the module');
                    } else {
                        $.LoadingOverlay('show');
                        
                        let module = $(event.target).data('module');
                        $.ajax({
                            url: 'includes/moduleutil.php?request=Modules&module=' + module,
                            type: 'DELETE',
                            cache: false,
                            context: this
                        }).done((result) => {
                            this.#buildUI();
                        }).always(() => {
                            $.LoadingOverlay('hide');
                        });              
                    }
                });

                $(document).on('click', '.module-enable', (event) => {
                    let module = $(event.target).data('module');
                    let state = $(event.target).is(':checked');

                    for (let key in this.#configData.selected) {
                        if (this.#configData.selected[key].module == module) {
                            this.#configData.selected[key].enabled = state;
                        }
                    }
                    for (let key in this.#configData.available) {
                        if (this.#configData.available[key].module == module) {
                            this.#configData.available[key].enabled = state;
                        }
                    }

                    $(document).trigger('module:dirty');
                });

                $(document).on('click', '.module-settings-button', (event) => {
                    this.#createSettingsDialog(event.target);
                    $('#module-settings-dialog').modal({
                        keyboard: false
                    });
                });

                $('#modules-selected').sortable({
                    group: 'list',
                    animation: 200,
                    ghostClass: 'ghost',
                    filter: '.filtered',              
                    onMove: function (evt) {

                        if (evt.related.classList.contains('filtered')) {
                            if (evt.related.classList.contains('first') && !evt.willInsertAfter) { 
                                return false;
                            }
                            if (evt.related.classList.contains('last') && evt.willInsertAfter) { 
                                return false;
                            }
                        }

                        if (evt.dragged.classList.contains("locked")) {
                            return false;
                        }
                    },
                    onEnd: (evt) => {
                        $(document).trigger('module:dirty');

                        if ($(evt.to).is($('#modules-available'))) {
                            let settingsButton = $('#' + $(evt.item).attr("id") + 'settings');
                            let enabledButton = $('#' + $(evt.item).attr("id") + 'enabled');
                            let deleteButton = $('#' + $(evt.item).attr("id") + 'delete');
							let addButton = $('#' + $(evt.item).attr("id") + 'add')
							if (settingsButton.length) {
                                settingsButton.css('display', 'none');
                            }
                            enabledButton.prop('disabled', true);
                            enabledButton.prop('checked', false);  
                            deleteButton.prop('disabled', false);
							addButton.css('display', 'inline-block')

							this.#checkDependencies()
                        }
                    }
                });

                $('#modules-available').sortable({
                    group: 'list',
                    animation: 200,
                    ghostClass: 'ghost',
                    filter: '.filtered',
                    onMove: function (evt) {

                        if (evt.related.classList.contains('filtered')) {
                            if (evt.related.classList.contains('first') && !evt.willInsertAfter) { 
                                return false;
                            }
                            if (evt.related.classList.contains('last') && evt.willInsertAfter) { 
                                return false;
                            }
                        }

                        if (evt.dragged.classList.contains('locked')) {
                            return false;
                        }
                    },
                    onEnd: (evt) => {
                        if ($(evt.to).is($('#modules-selected'))) {
							this.#moduleAdded(evt.item)
                        }
                    }
                });

                $(document).on('module:dirty', () => {
                    this.#dirty = true;
                    this.#updateToolbar();
                });

				this.#checkDependencies()
            });
        }).always(() => {
            $.LoadingOverlay('hide');
        });

		$(document).off('click', '#module-settings-dialog-test')
        $(document).on('click', '#module-settings-dialog-test', () => {
			this.#testModule()
		})       
    }

	#moduleAdded(item) {
		$(document).trigger('module:dirty');
		let settingsButton = $('#' + $(item).attr("id") + 'settings')
		let enabledButton = $('#' + $(item).attr("id") + 'enabled')
		let deleteButton = $('#' + $(item).attr("id") + 'delete')
		let addButton = $('#' + $(item).attr("id") + 'add')
		if (settingsButton.length) {
			settingsButton.css('display', 'inline-block')
		}
		enabledButton.prop('disabled', false)
		enabledButton.prop('checked', $.moduleeditor.settings.autoenable)
		deleteButton.prop('disabled', true)
		addButton.css('display', 'none')
		let element = $(item).find('.moduleenabler')
		let checked = $(element).prop('checked')
		let moduleName = $(element).data('module')
		let module = this.#findModuleData(moduleName)
		module.data.enabled = checked

		this.#checkDependencies()
	}

	#checkDependencies() {		
		let result = $.ajax({
			type: 'POST',
			url: 'includes/moduleutil.php?request=CheckModuleDependencies',
			data: {
				check: $('#modules-selected').sortable('toArray'),
				flow: this.#eventName
			},
			dataType: 'json',
			cache: false,
			async: false,
			context: this,
			success: function (result) {
				$('[data-id] .warning').css('display', 'none')
				if (result !== null && typeof result === 'object' && !Array.isArray(result)) {
					for (const module in result) {
						let moduleResult = result[module]
						if (moduleResult[this.#eventName] !== undefined) {
							$('[data-id="' + module + '"] .warning').css('display', 'inline-block')
							$('[data-id="' + module + '"] .warning').attr('title', 'Warning')
							$('[data-id="' + module + '"] .warning').data('content', moduleResult[this.#eventName])
						}
					}
					$('[data-toggle="popover"]').popover('destroy')
					$('[data-toggle="popover"]').popover()
				}
				this.#errors = result
			}                
		})
	}

    #updateToolbar() {
        if (this.#dirty) {
            $('#module-editor-save').addClass('green pulse');
            $('#module-editor-save').removeClass('disabled');
        } else {
            $('#module-editor-save').removeClass('green pulse');
            $('#module-editor-save').addClass('disabled');
        }

        if (this.#configData !== null) {
            if (this.#configData.corrupted) {
                $('#module-editor-reset').addClass('green pulse');
                if (this.#configData.restore) {
                    $('#module-editor-restore').addClass('green pulse');
                }
            } else {
                $('#module-editor-reset').removeClass('green pulse');
                $('#module-editor-restore').removeClass('green pulse');
            }
        }

        if (this.#settings.settings.debugmode) {
            $('#oe-toolbar-debug').removeClass('hidden');
        } else {
            $('#oe-toolbar-debug').addClass('hidden');
        }
    }

    alignModal() {
        let modalDialog = $(this).find('.modal-dialog');
        modalDialog.css('margin-top', Math.max(0, ($(window).height() - modalDialog.height()) / 2));
    }

    #addModules(moduleData, element) {
        for (let key in moduleData) {
            let data = moduleData[key];
            let moduleKey = 'allsky' + key;
            let template = this.#createModuleHTML(data, element, moduleKey);
            $(element).append(template);
        }
    }

    #createModuleHTML(data, element, moduleKey) {
        let settingsHtml = '';
        if (data.metadata.arguments !== undefined) {
            if (Object.entries(data.metadata.arguments).length != 0) {
                let disabled = '';
                if (element == '#modules-available') {
                    disabled = 'disabled="disabled"';
					disabled = 'style="display: none"'
                }
                settingsHtml = '<button type="button" class="btn btn-sm btn-primary module-settings-button" id="' + moduleKey + 'settings" data-module="' + data.module + '" ' + disabled + '>Settings</button>';
            }
        }

        let locked = '';
        let enabledHTML = '';
        if (data.position !== undefined) {
            locked = 'filtered locked ' + data.position;
        } else {
            let enabled = '';
            if (data.enabled !== undefined) {
                if (data.enabled) {
                    enabled = 'checked="checked"';
                }
            }
            enabledHTML = '<div class="pull-right module-enable"><span class="module-enable-text">Enabled</span> <input type="checkbox" class="moduleenabler" ' + enabled + ' id="' + moduleKey + 'enabled" data-module="' + data.module + '"></div>';
        }

        let deleteHtml = '';
       /* if (data.type !== undefined) {
            if (data.type == 'user') {
                let disabled = '';
                if (element == '#modules-selected') {
                    disabled = 'disabled="disabled"';
                }
                deleteHtml = '<button type="button" class="btn btn-sm btn-danger module-delete-button" id="' + moduleKey + 'delete" data-module="' + data.module + '" ' + disabled + '>Delete</button>';
            }
        }*/

		let addHTML = ''
		if (element == '#modules-available') {
			let popover = 'data-toggle="popover" data-delay=\'{"show": 1000, "hide": 200}\' data-placement="top" data-trigger="hover" title="Add Module" data-content="Adds the ' + data.metadata.name + ' to the selected modules"'
			addHTML = '<button type="button" class="btn btn-sm btn-success module-add-button ml-2" id="' + moduleKey + 'add" data-module="' + moduleKey + '" ' + popover + '>>></button>';
		}

        let disabled = '';
        if (element == '#modules-available') {
            disabled = 'disabled="disabled"';
        }

        let experimental = '';
        if (data.metadata.experimental) {
            experimental = '<span class="module-experimental">EXPERIMENTAL:</span> ';
        } 

        let version = this.#settings.version;
        if (data.metadata.version !== undefined) {
            version = data.metadata.version;
        }
        version = '<span><small class="module-version">' + version + '</small><span>';
		let template = '\
            <div id="' + moduleKey + '" data-id="' + data.module + '" class="list-group-item ' + locked + '"> \
                <div class="panel panel-default"> \
                    <div class="panel-heading"><span class="warning" data-toggle="popover" data-delay=\'{"show": 1000, "hide": 200}\' data-placement="top" data-trigger="hover" data-placement="top" title="" data-content=""><i class="fa-solid fa-2x fa-triangle-exclamation"></i> </span>' + data.metadata.name + ' ' + version + ' ' + enabledHTML + '</div> \
                    <div class="panel-body">' + experimental + data.metadata.description + ' <div class="pull-right">' + deleteHtml + ' ' + settingsHtml + addHTML + '</div></div> \
                </div> \
            </div>';

        return template;
    }

    #findModuleData(module) {
        let moduleData = null;

        for (let key in this.#configData.available) {
            let data = this.#configData.available[key];
            if (data.module === module) {
                moduleData = {
                    module: key,
                    data: data
                };
                break;
            }
        }

        if (moduleData === null) {
            for (let key in this.#configData.selected) {
                let data = this.#configData.selected[key];
                if (data.module === module) {
                    moduleData = {
                        module: key,
                        data: data
                    };
                    break;
                }
            }
        }

        return moduleData;
    }

    #createSettingsDialog(target) {
        var events = []
        this.#events = []
        let tabs = []
		this.#dialogFilters = []

        target = $(target)
        let module = target.data('module')
        let moduleShortName = module.replace('.py', '')
        moduleShortName = moduleShortName.replace('allsky_', '')
        let moduleData = this.#findModuleData(module)
        moduleData = moduleData.data

        let fieldsHTML = ''
        let args = moduleData.metadata.argumentdetails
        for (let key in args) {
            let fieldData = args[key]
			let fieldHTML = ''
			let fieldType = null
            if (fieldData.type !== undefined) {
				if (fieldData.type.fieldtype !== undefined) {				
                	fieldType = fieldData.type.fieldtype
				}
			}

			if (fieldType !== 'text') {
				let extraClass = 'input-group-allsky';

				let required = '';
				if (fieldData.required !== undefined) {
					if (fieldData.required == 'true') {
						required = ' required ';
					}
				}

				let fieldDescription = ' data-description="' + fieldData.description + '" ';
				let helpText = '';
				if (fieldData.help !== undefined) {
					if (fieldData.help !== '') {
						helpText = '<p class="help-block">' + fieldData.help + '</p>';
					}
				}

				let fieldValue = '';
				if (moduleData.metadata.arguments[key] !== undefined) {
					fieldValue = moduleData.metadata.arguments[key];
				}

                let disabled = ''
                if (fieldData.disabled !== undefined) {
                    if (fieldData.disabled) {
                        disabled = ' disabled="disabled" '
                    }
                }
				let inputHTML = '<input ' + disabled + ' id="' + key + '" name="' + key + '" class="form-control" value="' + fieldValue + '"' + required + fieldDescription + '>';
				if (fieldType !== null) {
					let fieldTypeData = fieldData.type;
					if (fieldType == 'spinner') {
						let min = '';
						if (fieldType.min !== undefined) {
							min = 'min="' + fieldType.min + '"';
						}
						let max = '';
						if (fieldType.max !== undefined) {
							max = 'max="' + fieldType.max + '"';
						}
						let step = '';
						if (fieldType.step !== undefined) {
							step = 'step="' + fieldType.step + '"';
						}
						inputHTML = '<input id="' + key + '" name="' + key + '" type="number" ' + min + ' ' + max + ' ' + step + ' class="form-control" value="' + fieldValue + '"' + required + fieldDescription + '>'
						extraClass = 'input-group';
					}

					if (fieldType == 'checkbox') {
						let checked = '';
						if (this.#convertBool(fieldValue) == true) {
							checked = 'checked="checked"';
						}
						inputHTML = '<input type="checkbox" id="' + key + '" name="' + key + '" ' + checked + ' value="checked"' + required + fieldDescription + '>';
						extraClass = 'input-group';
					}

					if (fieldType == 'image' || fieldType == 'mask') {
						inputHTML = '<input id="' + key + '" name="' + key + '" class="form-control" value="' + fieldValue + '"' + required + fieldDescription + '>';
						extraClass = 'input-group';
						inputHTML = '\
							<div class="row">\
								<div class="col-xs-8">\
								' + inputHTML + '\
								</div>\
								<div class="col-xs-4">\
									<button type="button" class="btn btn-default" id="open-image-manager-' + key + '">...</button>\
								</div>\
							</div>\
						';

                        let validate = null;
                        if (fieldType == 'mask') {
                            validate = 'includes/moduleutil.php?request=ValidateMask'
                        }

						$(document).off('click', '#open-image-manager-' + key)                
                        $(document).on('click', '#open-image-manager-' + key, (event) => {                
							$('#module-image-manager').oeImageManager({
								thumbnailURL: 'includes/overlayutil.php?request=Images',
								usedImages: [],
								bind: '#' + key,
                                validate: validate,
								allowDoubleClick: true
							});
							$('#module-file-manager-dialog').modal({
								keyboard: false
							});
						});

						$('#module-file-manager-dialog').off('hidden.bs.modal')
                        $('#module-file-manager-dialog').on('hidden.bs.modal', () => {
							$('#module-image-manager').data('oeImageManager').destroy();
						});                    

						$(document).off('oe-imagemanager-add')
                        $(document).on('oe-imagemanager-add', (event, image) => {
							$('#module-file-manager-dialog').modal('hide')
						});
						
					}

					if (fieldType == 'roi') {
						inputHTML = '<input id="' + key + '" name="' + key + '" class="form-control" disabled="disabled" value="' + fieldValue + '"' + required + fieldDescription + '>';
						extraClass = 'input-group';
						inputHTML = '\
							<div class="row">\
								<div class="col-xs-8">\
								' + inputHTML + '\
								</div>\
								<div class="col-xs-4">\
									<button type="button" class="btn btn-default" id="open-roi-' + key + '" data-source="' + key + '">...</button>\
									<button type="button" class="btn btn-default" id="reset-roi-' + key + '" data-source="' + key + '"><i class="fa-solid fa-rotate-right"></i></button>\
								</div>\
							</div>\
						';

						$(document).off('click', '#reset-roi-' + key)
                        $(document).on('click', '#reset-roi-' + key, (event) => {
							let el = $(event.currentTarget).data('source');
							$('#' + el).val('');
						});

						$(document).off('click', '#open-roi-' + key)
                        $(document).on('click', '#open-roi-' + key, (event) => {
							let el = $(event.currentTarget).data('source');
							let data = $('#' + el).val();
							let roi = null;
							
							if (data !== '') {
								roi = this.#parseROI(data);
							}

							let fallbackValue = $('#roifallback').val();
							if (fallbackValue === undefined) {
								fallbackValue = 5;
							}

							$.allskyROI({
								id: key,
								roi: roi,
								fallbackValue: fallbackValue,
								imageFile : this.#settings.filename,
								roiSelected: function(roi) {
									$('#' + key).val(roi.x1 + ',' + roi.y1 + ',' + roi.x2 + ',' + roi.y2)
								}
							});
						});
					}

					if (fieldType == 'gpio') {
						inputHTML = '<input id="' + key + '" name="' + key + '" class="form-control" disabled="disabled" value="' + fieldValue + '"' + required + fieldDescription + '>';
						extraClass = 'input-group';
						inputHTML = '\
							<div class="row">\
								<div class="col-xs-8">\
								' + inputHTML + '\
								</div>\
								<div class="col-xs-4">\
									<button type="button" class="btn btn-default" id="open-gpio-' + key + '" data-source="' + key + '">...</button>\
									<button type="button" class="btn btn-default" id="reset-gpio-' + key + '" data-source="' + key + '"><i class="fa-solid fa-rotate-right"></i></button>\
								</div>\
							</div>\
						';

						$(document).off('click', '#reset-gpio-' + key)
                        $(document).on('click', '#reset-gpio-' + key, (event) => {
							let el = $(event.target).data('source');
							$('#' + el).val('');
						});

						$(document).off('click', '#open-gpio-' + key)
                        $(document).on('click', '#open-gpio-' + key, (event) => {
							let el = $(event.target).data('source');
							let data = $('#' + el).val();

							$.allskyGPIO({
								id: key,
								gpio: parseInt(data),
								gpioSelected: function(gpio) {
									$('#' + key).val(gpio)                                
								}
							});
						});                    
					}

					if (fieldType == 'variable') {
						inputHTML = '<input id="' + key + '" name="' + key + '" class="form-control" disabled="disabled" value="' + fieldValue + '"' + required + fieldDescription + '>';
						extraClass = 'input-group';
						inputHTML = '\
							<div class="row">\
								<div class="col-xs-8">\
								' + inputHTML + '\
								</div>\
								<div class="col-xs-4">\
									<button type="button" class="btn btn-default" id="open-var-' + key + '" data-source="' + key + '">...</button>\
									<button type="button" class="btn btn-default" id="reset-var-' + key + '" data-source="' + key + '"><i class="fa-solid fa-rotate-right"></i></button>\
								</div>\
							</div>\
						';

                        $(document).off('click', '#reset-var-' + key)                        
						$(document).on('click', '#reset-var-' + key, (event) => {
							let el = $(event.target).data('source');
							$('#' + el).val('');
						});

						$(document).off('click', '#open-var-' + key)
                            $(document).on('click', '#open-var-' + key, (event) => {
							let el = $(event.target).data('source');
							let data = $('#' + el).val();

							$.allskyVariable({
								id: key,
								variable: data,
								variableSelected: function(variable) {
									$('#' + key).val(variable)                                
								}
							});
						});
                                              
					}

					if (fieldType == 'i2c') {
						inputHTML = '<input id="' + key + '" name="' + key + '" class="form-control" value="' + fieldValue + '"' + required + fieldDescription + '>';
						extraClass = 'input-group';
						inputHTML = '\
							<div class="row">\
								<div class="col-xs-8">\
								' + inputHTML + '\
								</div>\
								<div class="col-xs-4">\
									<button type="button" class="btn btn-default" id="open-i2c-' + key + '" data-source="' + key + '">...</button>\
									<button type="button" class="btn btn-default" id="reset-i2c-' + key + '" data-source="' + key + '"><i class="fa-solid fa-rotate-right"></i></button>\
								</div>\
							</div>\
						';

						$(document).off('click', '#reset-i2c-' + key)
                        $(document).on('click', '#reset-i2c-' + key, (event) => {
							let el = $(event.target).data('source');
							$('#' + el).val('');
						});

						$(document).off('click', '#open-i2c-' + key)
                        $(document).on('click', '#open-i2c-' + key, (event) => {
							var el = $(event.target).data('source');
							let data = $('#' + el).val();

							$.allskyI2C({
								address: data,
								i2cSelected: (address) => {
									$('#' + key).val(address)                                 
								}
							});
						});						
					}

					if (fieldType == 'select') {
						inputHTML = '<select name="' + key + '" id="' + key + '"' + required + fieldDescription + '>';
						let values = fieldData.type.values.split(',');
						for (let value in values) {
							let optionValue = values[value];
							let selected = "";
							if (fieldValue == optionValue) {
								selected = ' selected="selected" ';
							}
							inputHTML += '<option value="' + optionValue + '"' + selected + '>' + optionValue + '</option>';
						}
						inputHTML += '</select>';
					}					
				}

				fieldHTML = '\
					<div class="form-group" id="' + key + '-wrapper">\
						<label for="' + key + '" class="control-label col-xs-4">' + fieldData.description + '</label>\
						<div class="col-xs-8">\
							<div class="'+ extraClass + '">\
								' + inputHTML + '\
							</div>\
							' + helpText + '\
						</div>\
					</div>\
				';

			} else {
				let fieldError = true
				if (fieldData.type.style !== undefined) {
					let style = fieldData.type.style

					if (style.alert !== undefined) {
						let css = 'success'
						if (style.alert.class !== undefined) {
							css = style.alert.class
						}
						fieldHTML = '<div class="alert alert-' + css + '" role="alert">' + fieldData.message + '</div>'
						fieldError = false
					}

					let width = 'full'
					if (style.width !== undefined) {
						width = style.width
					}
					
					switch (width) {
						case 'full':
							fieldHTML = '<div class="row" id="' + key + '-wrapper"><div class="col-xs-12">' + fieldHTML + '</div></div>'
						  break;
						case 'left':
							fieldHTML = '<div class="row" id="' + key + '-wrapper"><div class="col-xs-4">' + fieldHTML + '</div></div>'
						  break;
						case 'right':
							fieldHTML = '<div class="row" id="' + key + '-wrapper"><div class="col-xs-offset-4"><div class="col-xs-8">' + fieldHTML + '</div></div></div>'
						  break;
					  }

				}
				if (fieldError) {
					fieldHTML = '<p>' + fieldData.message + '</p>'
				}
			}

            let tab = 'Settings';
            if (fieldData.tab !== undefined) {
                tab = fieldData.tab
                tab = tab.replace(/\s+/g,'_');
            }
            if (tabs[tab] === undefined) {
                tabs[tab] = [];
            }
            tabs[tab].push(fieldHTML);
            fieldsHTML += fieldHTML;

			if (fieldData.filters !== undefined) {
				let filters = fieldData.filters
				if (this.#dialogFilters[filters.filter] === undefined) {
					this.#dialogFilters[filters.filter] = {}
				}
				for (let [filterkey, value] of Object.entries(filters.values)) {
					if (this.#dialogFilters[filters.filter][value] === undefined) {
						this.#dialogFilters[filters.filter][value] = {}
					}
					this.#dialogFilters[filters.filter][value][key] = filters.filtertype
				}
			}			
        }
        let moduleSettingsHtml = '';
        let numberOfTabs = Object.keys(tabs).length;
        if (numberOfTabs === 1 && moduleData.metadata.extradata === undefined) {
            for (let tabName in tabs) {
                for (let field in tabs[tabName]) {
                    moduleSettingsHtml += tabs[tabName][field];
                }
            }
        } else {
            moduleSettingsHtml += '<div>';
            moduleSettingsHtml += ' <ul class="nav nav-tabs" role="tablist">'
            let active = 'active';
            for (let tabName in tabs) {
                let tabRef = moduleData.metadata.module + tabName;
                moduleSettingsHtml += '<li role="presentation" class="' + active + '"><a href="#' + tabRef + '" role="tab" data-toggle="tab">' + tabName.replace(/\_/g,' ') + '</a></li>';
                active = '';
            }

            if ('extradata' in moduleData.metadata) {
                moduleSettingsHtml += '<li role="presentation"><a href="#as-module-var-list" role="tab" data-toggle="tab">Variables</a></li>';
            }

            if (moduleShortName in this.#configData.help) {
                moduleSettingsHtml += '<li role="presentation"><a href="#as-module-var-help" role="tab" data-toggle="tab">Help</a></li>';
            }

            moduleSettingsHtml += ' </ul>'

            moduleSettingsHtml += '<div class="tab-content">';
            active = 'active';
            for (let tabName in tabs) {
                let fieldsHTML  = '';
                for (let field in tabs[tabName]) {
                    fieldsHTML += tabs[tabName][field];
                }                
                let tabRef = moduleData.metadata.module + tabName;
                moduleSettingsHtml += '<div role="tabpanel" style="margin-top:10px" class="tab-pane ' + active + '" id="' + tabRef + '">' + fieldsHTML + '</div>';
                active = '';
            }

            if ('extradata' in moduleData.metadata) {
                moduleSettingsHtml += '\
                    <div role="tabpanel" style="margin-top:10px" class="tab-pane" id="as-module-var-list">\
                        <div class="alert alert-success" role="alert">The table shows all variables that this module can generate. Where ${COUNT} appears it means that the module can generate multiple variables with ${COUNT} replaced by a number.<br>Any other ${} variables will be replaced with the relevant content - See the module documentation for more details</div>\
                        <table id="as-module-var-list-table" class="display compact as-variable-list" style="width:98%;">\
                            <thead>\
                                <tr>\
                                    <th>Variable</th>\
                                    <th>Type</th>\
                                    <th>Description</th>\
                                </tr>\
                            </thead>\
                        </table>\
                    </div>'
            }

            if (moduleShortName in this.#configData.help) {
                moduleSettingsHtml += '\
                    <div role="tabpanel" style="margin-top:10px" class="tab-pane" id="as-module-var-help">\
                    ' + this.#configData.help[moduleShortName].html + '\
                    </div>'
            }

            moduleSettingsHtml += '</div>';
            moduleSettingsHtml += '</div>';
        }
        let experimental = '';
        if (moduleData.metadata.experimental) {
            experimental = '<span class="module-experimental module-experimental-header"> - Experimental. Please use with caution</span>';
        }

        let testButton = '\
        	<div class="hidden as-module-test">\
                <div class="pull-left hidden as-module-test" id="module-settings-dialog-test-wrapper">\
                    <button type="button" class="btn btn-success form-control" id="module-settings-dialog-test">Test Module</button>\
                </div>\
                <div class="pull-left hidden ml-3 as-module-test" id="module-settings-dialog-test-wrapper">\
                    <div class="switch-field boxShadow as-module-test-option-wrapper">\
                        <input id="switch_no_as-module-test-option" class="form-control" type="radio" name="as-module-test-option" value="day" checked />\
                        <label style="margin-bottom: 0px;" for="switch_no_as-module-test-option">Day</label>\
                        <input id="switch_yes_as-module-test-option" class="form-control" type="radio" name="as-module-test-option" value="night" />\
                        <label style="margin-bottom: 0px;" for="switch_yes_as-module-test-option">Night</label>\
                    </div>\
                </div>\
            </div>'

		let errorHTML = ''
		if (this.#errors[module] !== undefined) {
			if (this.#errors[module][this.#eventName] !== undefined) {
				let text = this.#errors[module][this.#eventName];
				errorHTML = '<div class="alert alert-warning mt-4 mb-4" role="alert">WARNING: ' + text + '</div>'
			}
		}
        let dialogTemplate = '\
            <div class="modal" role="dialog" id="module-settings-dialog" data-module="' + module + '">\
                <div class="modal-dialog modal-lg" role="document">\
                    <div class="modal-content">\
                        <div class="modal-header">\
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
                            <h4 class="modal-title"><strong>' + moduleData.metadata.name + ' Settings</strong> ' + experimental + '</h4>\
                        </div>\
                        <div class="modal-body">\
							' + errorHTML + '\
                            <form id="module-editor-settings-form" class="form-horizontal">\
                            ' + moduleSettingsHtml + '\
                            </form>\
                        </div>\
                        <div class="modal-footer">\
                            ' + testButton + '\
							<div class="pull-right">\
                            	<button type="button" class="btn btn-danger" data-dismiss="modal">Cancel</button>\
                            	<button type="button" class="btn btn-primary" id="module-settings-dialog-save">Save</button>\
                        	</div>\
                        </div>\
                    </div>\
                </div>\
            </div>\
        ';

        $('#module-settings-dialog').remove();
        $(document.body).append(dialogTemplate);

		if (moduleData.metadata.testable !== undefined) {
			if (moduleData.metadata.testable === 'true') {
				$('.as-module-test').removeClass('hidden')
			}
		}

		let centerModal = true
		if ('centersettings' in moduleData.metadata) {
			if (moduleData.metadata.centersettings === 'false') {
				centerModal = false
			}
		}
		if (centerModal) {
        	$('.modal').on('shown.bs.modal', this.alignModal);
		}

        if ('extradata' in moduleData.metadata) {
            $('#as-module-var-list-table').DataTable().destroy()
            $('#as-module-var-list-table').DataTable({
                ajax: {
                    url: 'includes/moduleutil.php?request=VariableList&module=' + module,
                    type: 'GET',
                    cache: false,
                    dataSrc: '',
                    data: function (d) {
                        d.timestamp = new Date().getTime();
                    }
                },
                dom: 'rtip',
                ordering: false,
                paging: true,
                pageLength: 20,
                autoWidth: false,            
                columns: [
                    { 
                        data: 'variable',
                        render: function(data, type, row, meta) {
                            let result = data
                            if (row.value !== '') {
                                result = '<b class="as-variable-has-value">' + data + '</b>'
                            }
                            return result
                        },
                        width: '30%'                        
                    },{
                        data: 'type',
                        width: '15%'                        
                    },{
                        data: 'description',
                        width: '55%'
                    }
                ]                
            })
        }

        $('#module-settings-dialog').off('hidden.bs.modal')
        $('#module-settings-dialog').on('hidden.bs.modal', () => {

        });

        $(window).off('resize')
        $(window).on('resize', (event) => {
            $('.modal:visible').each(this.alignModal);
        });

        $(document).off('click', '#module-settings-dialog-save')        
		$(document).on('click', '#module-settings-dialog-save', () => {
            let formErrors = this.#validateFormData();

            if (formErrors.length > 0) {
                let html = '<h4>Please correct the following errors before proceeding</h4>';
                html += '<ul>';
                formErrors.forEach(function(value, index) {
                    html += '<li>' + value + '</li>';
                });
                html += '</ul>';


                bootbox.dialog({
                    message: html,
                    title: '<h4><i class="fa-solid fa-2xl fa-triangle-exclamation"></i> Module Error(s)</h4>',
                    buttons: {
                        main: {
                            label: 'Close',
                            className: 'btn-primary'
                        }
                    },
                    className: 'module-error-dialog'
                });

            } else {
                let module = $('#module-settings-dialog').data('module');
                let formValues = this.#getFormValues()
                this.#saveFormData(this.#configData.selected, formValues, module);
                this.#saveFormData(this.#configData.available, formValues, module);

                $('#module-settings-dialog').modal('hide');
                $(document).trigger('module:dirty');
            }
        });

        $(document).off('change', 'select')
        $(document).on('change', 'select', (event) => {
			this.#setFormState()
        });

		this.#setFormState()
    }

	#getFormValues() {
		let formValues = {};
		$('#module-editor-settings-form :input:not([type=button])').each(function() {
			if (this.type == 'checkbox') {
				if ($(this).prop('checked')) {
					formValues[$(this).attr('name')] = true;
				} else {
					formValues[$(this).attr('name')] = false;
				}
			} else {
				formValues[$(this).attr('name')] = $(this).val();
			}
		});

		return formValues
	}

	#setFormState() {
		// Hide all fields that can be hidden / shown
		for (let [selectField, selectValues] of Object.entries(this.#dialogFilters)) {
			for (let [selectOption, fields] of Object.entries(selectValues)) {
				for (let [fieldToManage, filterType] of Object.entries(fields)) {
					$('#' + fieldToManage + '-wrapper').hide()
				}
			}
		}

		// Show just the fields based upon the select value
		for (let [selectField, selectValues] of Object.entries(this.#dialogFilters)) {
			let selectValue = $('#' + selectField).val()
			for (let [selectOption, fields] of Object.entries(selectValues)) {
				if (selectValue == selectOption) {
					for (let [fieldToManage, filterType] of Object.entries(fields)) {
						$('#' + fieldToManage + '-wrapper').show()
					}					
				}
			}
		}		
	}

    #createTestResultsMessage(message) {
        let messageHtml = this.#convertLineBreaksToBr(message)
        let html = '<div class="module-test-results">' + messageHtml + '</div>'
        return html
    }

    #displayTestResultsDialog(response, title) {
        var runInfo = bootbox.dialog({
            title: title,
            message: this.#createTestResultsMessage(response),
            size: 'large',
            buttons: {
                ok: {
                    label: 'Close',
                    className: 'btn-success',
                    callback: function(){
                        //runInfo.remove()
						runInfo.modal('hide').remove();
                    }
                }
            }
        });
    }

	#testModule() {
		let moduleFilename = $('#module-settings-dialog').data('module')
        let daynight = $('input[name=as-module-test-option]:checked').val()
		var module = moduleFilename.replace('allsky_', '')
		module = module.replace('.py', '')
		let formValues = this.#getFormValues()
        
        let moduleTemp = {}
        if (module in this.#configData.selected) {
            moduleTemp = Object.assign({}, this.#configData.selected[module]);
        } else {
            if (module in this.#configData.available) {
                moduleTemp = Object.assign({}, this.#configData.available[module]);
            }
        }

        this.#testData = {}
        this.#testData[module] = moduleTemp        
        this.#saveFormData(this.#testData, formValues, moduleFilename);
        
        let jsonData = JSON.stringify(this.#testData, null, 4);

        let overlayText = 'Testing Module - Please Wait'
        $('#module-settings-dialog .modal-content').LoadingOverlay('show', {
            background: 'rgba(0, 0, 0, 0.5)',
            imageColor: '#a94442',
            textColor: '#a94442',
            text: overlayText
        }); 

		$.ajax({
			url: 'includes/moduleutil.php?request=TestModule',
			type: 'POST',
			data: {
				module: module,
                dayNight: daynight,
				flow: jsonData
			},
            cache: false,
			dataType: 'json'
		})
		.then((response) => {
            let title = 'Module <b>' + module + '</b> run result'
            this.#displayTestResultsDialog(response, title)
		})
		.catch((error) => {
            let title = 'ERROR Running Module <b>' + module + '</b> run result'
            this.#displayTestResultsDialog(error.responseText, title)
		}).always(() => {
            $('#module-settings-dialog .modal-content').LoadingOverlay('hide')
        })
	}

    #convertLineBreaksToBr(input) {
        return input.replace(/\r\n|\n|\r/g, '<br>');
    }

    #convertBool(value) {
        let result = false;
        if (typeof value === 'boolean') {
            result = value;
        } else {
            if (typeof value === 'string') {
                value = value.toLowerCase();
                if (value === 'true') {
                    result = true;
                }
            }
        }
        return result;
    }

    #validateFormData() {
        let errors = [];
        $('#module-editor-settings-form :input:not([type=button])').each(function() {
            let required = $(this).attr('required');
            if (required !== undefined && required === 'required') {
                let value = $(this).val();
                if (value === '') {
                    let error =  $(this).data('description') + ' is required';
                    errors.push(error)
                }
            }
        });
        return errors;
    }

    #validateModuleData() {
        let errors = [];
        let moduleKeys = $('#modules-selected').sortable('toArray');
        for (let moduleKey in moduleKeys) {
            let moduleData = this.#findModuleData(moduleKeys[moduleKey]);
            if (moduleData.data.enabled) {
                if (moduleData.data.metadata.argumentdetails !== undefined) {
                    for (let key in moduleData.data.metadata.argumentdetails) {
                        let data = moduleData.data.metadata.argumentdetails[key];
                        if (data.required !== undefined) {
                            if (data.required == 'true') {
                                if (moduleData.data.metadata.arguments[key] !== undefined) {
                                    if (moduleData.data.metadata.arguments[key] == '') {
                                        let moduleName = moduleData.module;
                                        if (errors[moduleName] === undefined) {
                                            errors[moduleName] = {
                                                'module': moduleName,
                                                'description': moduleData.data.metadata.name,
                                                'errors': []
                                            };
                                        }
                                        let errorMessage = data.description + ' is a required field';
                                        errors[moduleName].errors.push(errorMessage)
                                    }
                            } 
                            }
                        }
                    }
                }
            }
        }
        return errors;
    }

    #parseROI(rawROI) {
        let roi = null;
        let parts = rawROI.split(',');

        if (parts.length == 4) {
            roi = {};
            roi.x1 = parseInt(parts[0], 10);
            roi.y1 = parseInt(parts[1], 10);
            roi.x2 = parseInt(parts[2], 10);
            roi.y2 = parseInt(parts[3], 10);
        }

        return roi;
    }

    #saveFormData(type, formValues, module) {
        for (let key in type) {
            if (type[key].module == module) {
                for (let paramKey in type[key].metadata.arguments) {
                    if (formValues[paramKey] !== undefined) {
                        let value = formValues[paramKey];
                        type[key].metadata.arguments[paramKey] = value;
                    }
                }
            }
        }
    }

    #saveConfig() {
        let errors = this.#validateModuleData();
        if (Object.keys(errors).length > 0) {

            let html = '<h4>Please correct the following errors before proceeding</h4>';
            html += '<ul>';
            for (let module in errors) {
                html += '<li> Module - ' + errors[module].description;
                html += '<ul>';
                for (let error in errors[module].errors) {
                    html += '<li>' + errors[module].errors[error] + '</li>';
                }
                html += '</ul>';
                html += '</li>';
            };
            html += '</ul>';


            bootbox.dialog({
                message: html,
                title: '<h4><i class="fa-solid fa-2xl fa-triangle-exclamation"></i> Module Error(s)</h4>',
                buttons: {
                    main: {
                        label: 'Close',
                        className: 'btn-primary'
                    }
                },
                className: 'module-error-dialog'
            });

        } else {
            $.LoadingOverlay('show');
            let newConfig = {};
            let moduleKeys = $('#modules-selected').sortable('toArray');
            for (let key in moduleKeys) {
                let moduleData = this.#findModuleData(moduleKeys[key])
                let enabled =  $('#allsky' + moduleData.module + 'enabled').prop('checked');
                if (enabled == undefined) {
                    enabled = true;
                }
                moduleData.data.enabled = enabled;
                newConfig[moduleData.module] = moduleData.data
            }

            let jsonData = JSON.stringify(newConfig, null, 4);

            $.ajax({
                url: 'includes/moduleutil.php?request=Modules',
                type: 'POST',
                dataType: 'json',
                data: { config: this.#eventName, configData: jsonData },
                cache: false,
                context: this
            }).done((result) => {


            }).always(() => {
                $.LoadingOverlay('hide');
            });

            this.#dirty = false;
            this.#updateToolbar();
        }
    }

    #showDebug() {
        $.ajax({
            url: 'includes/moduleutil.php?request=Modules&event=' + this.#eventName,
            type: 'GET',
            dataType: 'json',
            cache: false,
            context: this
        }).done((result) => {
            $('#module-editor-debug-dialog-content').empty();

            let totalTime = 0;
            let html = '';
            html += '<div class="row">';                
            html += '<div class="col-md-3"><strong>Module</strong></div>';
            html += '<div class="col-md-2"><strong>Run Time (s)</strong></div>';
            html += '<div class="col-md-7"><strong>Result</strong></div>';
            html += '</div>';

            for (let key in result.debug) {
                let data = result.debug[key];
                let runTime = parseFloat(data.lastexecutiontime);
                totalTime += runTime;

                html += '<div class="row">';                
                html += '<div class="col-md-3">' + key + '</div>';
                html += '<div class="col-md-2"><div class ="pull-right">' + runTime.toFixed(2) + '</div></div>';
                html += '<div class="col-md-7">' + data.lastexecutionresult + '</div>';
                html += '</div>';
            }            

            html += '<div class="row">';                
            html += '<div class="col-md-12">&nbsp;</div>';
            html += '</div>';

            html += '<div class="row">';                
            html += '<div class="col-md-3"><div class="pull-right"><strong>Total</strong></div></div>';
            html += '<div class="col-md-2"><div class="pull-right"><strong>' + totalTime.toFixed(2) + '</strong></div></div>';
            html += '<div class="col-md-7"></div>';
            html += '</div>';

            $('#module-editor-debug-dialog-content').append(html);

            $('#module-editor-debug-dialog').modal('show');            
        });

    }

    run() {

        $(document).on('click', '#module-editor-restore', (event) => {
            if (window.confirm('Are you sure you wish to restore this Flow. The last saved configuration for this flow will be restored. This process CANNOT be undone?')) {
                $.ajax({
                    url: 'includes/moduleutil.php?request=Restore&flow=' + this.#eventName,
                    type: 'GET',
                    cache: false,
                    context: this
                }).done((result) => {
                    this.#buildUI();
                });
            }
        });      


        $(document).on('click', '#module-editor-reset', (event) => {
            if (window.confirm('Are you sure you wish to reset this Flow. This process CANNOT be undone?')) {
                $.ajax({
                    url: 'includes/moduleutil.php?request=Reset&flow=' + this.#eventName,
                    type: 'GET',
                    cache: false,
                    context: this
                }).done((result) => {
                    this.#buildUI();
                });
            }
        });      

        jQuery(window).bind('beforeunload', ()=> {
            if (this.#dirty) {
                return ' ';
            } else {
                return undefined;
            }
        });

        $(document).on('click', '#module-options', () => {
            let loadingTimer = setTimeout(() => {
                $.LoadingOverlay('show', {text : 'Sorry this is taking longer than expected ...'});
            }, 500);

            $.ajax({
                url: 'includes/moduleutil.php?request=ModulesSettings',
                type: 'GET',
                dataType: 'json',
                cache: false,
                context: this
            }).done((result) => {
                this.#moduleSettings = result;
                if (result.periodictimer == undefined) {
                    result.periodictimer = 5;
                }
                //$('#enablewatchdog').prop('checked', result.watchdog);
                //$('#watchdog-timeout').val(result.timeout);                
                $('#autoenable').prop('checked', result.autoenable);
                $('#debugmode').prop('checked', result.debugmode);
                $('#periodic-timer').val(result.periodictimer);                  
                $('#module-editor-settings-dialog').modal('show');
            }).always(() => {
                clearTimeout(loadingTimer);
                $.LoadingOverlay('hide');
            });
        });

        
        $(document).on('click', '#module-editor-settings-dialog-save', () => {
            let loadingTimer = setTimeout(() => {
                $.LoadingOverlay('show', {text : 'Sorry this is taking longer than expected ...'});
            }, 500)

            //this.#moduleSettings.watchdog = $('#enablewatchdog').prop('checked');
            //this.#moduleSettings.timeout = $('#watchdog-timeout').val() | 0;
            this.#moduleSettings.autoenable = $('#autoenable').prop('checked');
            this.#moduleSettings.debugmode = $('#debugmode').prop('checked');

            this.#moduleSettings.periodictimer = $('#periodic-timer').val() | 0;

            this.#settings.settings = this.#moduleSettings;
            $.moduleeditor.settings = this.#settings.settings;

            this.#updateToolbar();

            $.ajax({
                url: 'includes/moduleutil.php?request=ModulesSettings',
                type: 'POST',
                data: {settings: JSON.stringify( this.#moduleSettings)},
                cache: false
            }).done((result) => {
                $('#module-editor-settings-dialog').modal('hide');
            }).fail((result) => {
                bootbox.alert('Failed to save the module settings configuration');
            }).always(() => {
                this.#buildUI();
                clearTimeout(loadingTimer);
                $.LoadingOverlay('hide');
            });

            $('#module-editor-settings-dialog').modal('hide');
        });

        $(document).on('click', '#module-editor-save', () => {
            this.#saveConfig();
        });

        $(document).on('click', '#module-toobar-debug-button', () => {
            this.#showDebug()
        });

        $(document).on('change', '#module-editor-config', (e) => {
            let val = $("#module-editor-config option").filter(":selected").val();
            let oldVal = $("#module-editor-config").data("current");
            let doIt = true
            if (this.#dirty) {
                if (!window.confirm('Are you sure. Changes to the current configuration will be lost')) {
                    doIt = false;
                }
            }
            if (doIt) {
                $('#module-editor-config').data("current", val);
                this.#buildUI();
            } else {
                $(e.target).val(oldVal);
                return false;
            }
        });

        this.#buildUI();
    }

}