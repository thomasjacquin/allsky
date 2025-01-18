"use strict";

; (function ($) {

    $.allskyVariable = function (element, options) {
        var defaults = {
            active: true,
            id: 'as-var',
            columns: [],
            collapseall: false,
            collapseallsky: true,
			stateKey: 'as-variables',
            variableSelected: function (variable) { }
        }

        if (options === undefined) {
            options = element;
            element = null;
        } else {
            element = $(element);
        }
        var plugin = this;


        plugin.settings = $.extend({}, defaults, options);

        let pluginPrefix = plugin.settings.id + '-' + Math.random().toString(36).substring(2, 9);

        plugin.mmId = pluginPrefix + '-allskyVariable';
        plugin.select = pluginPrefix + '-allsky-var-select';
		plugin.container = pluginPrefix + '-container';
        plugin.showAllButton = pluginPrefix + 'as-variable-show-all'
        plugin.refreshButton = pluginPrefix + 'as-variable-refresh'
        plugin.resetButton = pluginPrefix + 'as-variable-reset'		
		
        plugin.init = function () {
            buildUI();
            updateUI();
            setupEvents();
        }


        var setupEvents = function() {

            $('#' + plugin.mmId).on('hidden.bs.modal', function () {
                plugin.destroy()
            });

            $(document).on('click', '#' + plugin.mmId + '-save', (event) => {
                let rowData = $('#' + plugin.mmId + '-table').DataTable().row('.selected').data()
                if (rowData !== undefined) {
				    let selectedVariable = rowData.variable
                    plugin.settings.variableSelected.call(this, selectedVariable);
                }
                plugin.destroy()
            });	
            
            $(document).on('click', 'input[name="' + plugin.showAllButton + '"]', (event) => {
                var selectedValue = $('input[name="' + plugin.showAllButton + '"]:checked').val()
                let newUrl = 'includes/moduleutil.php?request=VariableList&showempty=' + selectedValue
                plugin.variableTable.ajax.url(newUrl).load()
            })            
            
            $(document).on('click', '#' + plugin.refreshButton, (event) => {
                plugin.variableTable.ajax.reload()
            })

            $(document).on('click', '#' + plugin.resetButton, (event) => {
                localStorage.removeItem(plugin.settings.stateKey)
				buildTable()
            })

        }

        var updateUI = function() {
			$('#' + plugin.select).empty();
			$('#' + plugin.select).append('<option value="">No Variable</option>');
			$.each(plugin.settings.variables, function(index, value) {
				$('#' + plugin.select).append('<option value="' + index + '">' + index + ' (' + value + ')</option>');
			});

        }

        var buildUI = function() {
            $('#' + plugin.mmId + '-table').DataTable().destroy()

            let variableHTML = '\
                <div class="modal as-variables" role="dialog" id="' + plugin.mmId + '">\
                    <div class="modal-dialog modal-lg" role="document">\
                        <div class="modal-content">\
                            <div class="modal-header">\
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
                                <h4 class="modal-title">Select Allsky Variable</h4>\
                            </div>\
                            <div class="modal-body">\
                                <div class="as-var" id="' + plugin.container + '">\
                                    <form class="form-horizontal">\
                                        <div class="form-group toolbar">\
                                            <label for="' + plugin.showAllButton  + '" class="col-md-2 col-md-offset-7 control-label">Show All Variables</label>\
                                            <div class="col-md-3">\
                                                <div class="switch-field boxShadow ' + plugin.showAllButton + '">\
                                                    <input id="switch_no_' + plugin.showAllButton + '" class="form-control" type="radio" name="' + plugin.showAllButton + '" value="no" checked >\
                                                    <label style="margin-bottom: 0px;" for="switch_no_' + plugin.showAllButton + '">No</label>\
                                                    <input id="switch_yes_' + plugin.showAllButton + '" class="form-control" type="radio" name="' + plugin.showAllButton + '" value="yes" >\
                                                    <label style="margin-bottom: 0px;" for="switch_yes_' + plugin.showAllButton + '">Yes</label>\
                                                </div>\
                                            </div>\
                                        </div>\
                                    </form>\
                                    <div>\
                                        <table id="' + plugin.mmId + '-table" class="display compact as-variable-list" style="width:98%;">\
                                            <thead>\
                                                <tr>\
                                                    <th>Variable</th>\
                                                    <th>Group</th>\
                                                    <th>Source</th>\
                                                    <th>Value</th>\
                                                    <th>Format</th>\
                                                    <th>Sample</th>\
                                                    <th>Type</th>\
                                                    <th>Description</th>\
                                                </tr>\
                                            </thead>\
                                        </table>\
                                    </div>\
                                </div>\
                            </div>\
                            <div class="modal-footer">\
                                <div class="pull-left">\
                                    <button type="button" class="btn btn-success" id="' + plugin.refreshButton + '" data-toggle="tooltip" data-placement="top" title="Reload the Allsky Variables">Refresh</button>\
                                    <button type="button" class="btn btn-info ml-2" id="' + plugin.resetButton + '" data-toggle="tooltip" data-placement="top" title="Reset the column states to default">Reset</button>\
                                </div>\
                                <div class="pull-right">\
                                    <button type="button" class="btn btn-danger" data-dismiss="modal">Cancel</button>\
                                    <button type="button" class="btn btn-primary" id="' + plugin.mmId + '-save">Select</button>\
                                </div>\
                            </div>\
                        </div>\
                    </div>\
                </div>\
            ';


			$('#' + plugin.mmId).remove();
            $(document.body).append(variableHTML);	

			$('#' + plugin.mmId).on('shown.bs.modal', function () {
				buildTable()
			});

            $('#' + plugin.mmId).modal({
                keyboard: false
            });

			$('[data-toggle="tooltip"]').tooltip()
        }

		var buildTable = function() {

			$('#' + plugin.mmId + '-table').off('preXhr.dt')
			$('#' + plugin.mmId + '-table').off('xhr.dt')
			$('#' + plugin.mmId + '-table').DataTable().destroy()

            var collapsedGroups = {};
            plugin.variableTable = $('#' + plugin.mmId + '-table')
			.on('preXhr.dt', function (e, settings, data) {
				$('#' + plugin.mmId + ' .modal-dialog').LoadingOverlay('show', {
					background: 'rgba(0, 0, 0, 0.5)',
					imageColor: '#a94442',
					textColor: '#a94442',
					text: 'Loading Variables'
				}); 				
			})			
			.on('xhr.dt', function (e, settings, json, xhr) {
				if (plugin.settings.collapseallsky) {
					collapsedGroups['Allsky'] = true
				} else {
					if (plugin.settings.collapseall) {
						Object.entries(result).forEach(([key, value]) => {
							collapsedGroups[value.group] = true
						});
					}
				}
				$('#' + plugin.mmId + ' .modal-dialog').LoadingOverlay('hide')
			})			
			.DataTable({
                ajax: {
					url: 'includes/moduleutil.php?request=VariableList&showempty=no',
					dataSrc : '',
					type: 'GET',
					dataType: 'json',
					cache: false				
				},
				stateSave: true,
				stateSaveCallback: function (settings, data) {
					localStorage.setItem(plugin.settings.stateKey, JSON.stringify(data));
				},
				stateLoadCallback: function (settings) {
					return JSON.parse(localStorage.getItem(plugin.settings.stateKey));
				},				
                layout: {
                    topStart: {
						buttons: [
							{
								extend: 'colvis',
								text: 'Select Columns',
								className: 'btn btn-success'
							}
						]
                    }
                },
                colReorder: true,
				order: [[1, 'asc']],
                paging: false,
                select: {
                    style: 'single'
                },
				scrollY: '50vh',
				scrollCollapse: true,
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
                        data: 'group',
                        visible: true
                    },{
                        data: 'source',                        
                        visible: false
                    },{ 
                        data: 'value',
                        render: function(data, type, row, meta) {
                            let result = data
                            if (result !== null && typeof result === 'object') {
                                let toolTip = ''
                                Object.entries(result).forEach(([key, value]) => {
                                    toolTip += `${key} = ${value}, `
                                });
                                toolTip = toolTip.slice(0, -2)
                                result = data.value + '&nbsp;<a href="#" data-toggle="tooltip" title="' + toolTip + '">Options</a>'
                            }
                            return result
                        },                        
                        width: '20%'                        
                    },{
                        data: 'format',
                        visible: false,                        
                        width: '20%'                         
                    },{
                        data: 'sample',
                        visible: false,                        
                        width: '20%'                        
                    },{
                        data: 'type',
                        width: '10%'
                    },{
                        data: 'description'
                    }
                ],
                rowGroup: {
                    dataSrc: 'group',
                    startRender: function (rows, group) {
                        var collapsed = !!collapsedGroups[group];
                        
                        let icon = collapsed ? '<i class="fa-solid fa-angles-right"></i>' : '<i class="fa-solid fa-angles-down"></i>';
                        rows.nodes().each(function (r) {
                            r.style.display = collapsed ? 'none' : '';
                        });    

                        return $('<tr/>')
                            .append('<td colspan="9">' + icon + ' ' + group + ' (' + rows.count() + ' Variables)</td>')
                            .attr('data-name', group)
                            .toggleClass('collapsed', collapsed);
                    }
                }                
            })
            
            if (plugin.settings.columns.length > 0) {
                plugin.variableTable .columns().every(function (index) {
                    let columnHeader = this.header()
                    let columnName = $(columnHeader).text().toLowerCase()
                    if (plugin.settings.columns.includes(columnName)) {
                        plugin.variableTable.column(index).visible(true)
                    } else {
                        plugin.variableTable.column(index).visible(false)
                    }
                })
            }
            
            
            $('#' + plugin.mmId + '-table tbody').on('click', 'tr.dtrg-start', function () {
                var name = $(this).data('name');
                collapsedGroups[name] = !collapsedGroups[name];
                plugin.variableTable.draw(false);
            });
		}

        plugin.destroy = function () {
            $('#' + plugin.mmId).remove()
            $('#' + plugin.mmId).off('hidden.bs.modal')
			$('#' + plugin.mmId + '-table').off('preXhr.dt')
			$('#' + plugin.mmId + '-table').off('xhr.dt')			
            $(document).off('click', '#' + plugin.mmId + '-save')
            $(document).off('click', 'input[name="' + plugin.showAllButton + '"]')
            $(document).off('click', '#' + plugin.refreshButton)
            $(document).off('click', '#' + plugin.resetButton)
            $('#' + plugin.mmId + '-table').DataTable().destroy()
            $(document).removeData('allskyVariable');
        }

        plugin.init();       
    }

    $.fn.allskyVariable = function (options) {
        return this.each(function () {
            if (undefined == $(this).data('allskyVariable')) {
                var plugin = new $.allskyVariable(this, options);
                $(this).data('allskyVariable', plugin);
            }
        });
    }

})(jQuery);