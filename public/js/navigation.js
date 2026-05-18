    (function ($) {
        'use strict';

        var $builder = $('.df-nav-builder');
        var menuId = $builder.data('menu-id');

        function adminUrl(path) {
            return '/admin/' + path.replace(/^\//, '');
        }

        function initDragDrop() {
            $('.sortable-root, .df-menu-list').sortable({
                connectWith: '.sortable-root, .df-menu-list',
                placeholder: 'df-menu-placeholder',
                tolerance: 'pointer',
                items: '> li.df-menu-item, > .df-source-item',
                forcePlaceholderSize: true,
                receive: function (event, ui) {
                    var $dropped = ui.item;

                    if (!$dropped.hasClass('df-source-item')) {
                        return;
                    }

                    var $targetList = $dropped.closest('ol');
                    var index = $dropped.index();

                    var payload = {
                        type: $dropped.data('type'),
                        object_id: $dropped.data('object-id'),
                        object_type: $dropped.data('object-type'),
                        label: $dropped.data('label'),
                        url: $dropped.data('url'),
                        display_label: $dropped.data('label'),
                        display_url: $dropped.data('url'),
                        new_window: 0
                    };

                    $dropped.remove();

                    addItem(payload, function (item) {
                        var $html = $(itemHtml(item));
                        var $children = $targetList.children('li.df-menu-item');

                        if (index >= $children.length) {
                            $targetList.append($html);
                        } else {
                            $html.insertBefore($children.eq(index));
                        }

                        initDragDrop();
                        toast('Menu item added.');
                    });
                }
            }).disableSelection();

            $('.df-source-item').draggable({
                helper: function () {
                    var $source = $(this);
                    var width = $('.sortable-root').outerWidth();

                    return $(
                        '<li class="df-menu-item df-menu-pending" style="width:' + width + 'px;">' +
                        '<div class="df-menu-bar">' +
                        '<span class="df-menu-handle"><i class="fa fa-arrows"></i></span> ' +
                        '<strong>' + escapeHtml($source.data('label')) + '</strong>' +
                        '<span class="pull-right text-muted">' + escapeHtml($source.data('type')) + '</span>' +
                        '</div>' +
                        '</li>'
                    ).data({
                        type: $source.data('type'),
                        object_id: $source.data('object-id'),
                        object_type: $source.data('object-type'),
                        label: $source.data('label'),
                        url: $source.data('url')
                    });
                },
                connectToSortable: '.sortable-root',
                revert: 'invalid',
                appendTo: 'body',
                zIndex: 10000
            });
        }

        $(function () {
            initDragDrop();
        });

        function toast(message, type) {
            var klass = type === 'error' ? 'alert-danger' : 'alert-success';
            var $wrap = $('.df-toast');
            if (!$wrap.length) {
                $wrap = $('<div class="df-toast"></div>').appendTo('body');
            }
            var $alert = $('<div class="alert ' + klass + ' alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button>' + message + '</div>');
            $wrap.append($alert);
            setTimeout(function () { $alert.fadeOut(250, function () { $(this).remove(); }); }, 3000);
        }

        function post(path, data, done) {
            $.ajax({
                url: adminUrl(path),
                type: 'POST',
                data: data,
                dataType: 'json'
            }).done(function (res) {
                if (res && (res.success || res.ok)) {
                    if (done) done(res);
                    return;
                }

                toast(res && res.message ? res.message : 'Unable to complete the request.', 'error');
            }).fail(function () {
                toast('Unable to complete the request.', 'error');
            });
        }

        function itemHtml(item) {
            var isCustom = item.type === 'custom';
            var readonly = isCustom ? '' : ' readonly';

            return '<li class="df-menu-item" data-id="' + item.id + '">' +
                '<div class="df-menu-bar"><span class="df-drag"><i class="fa fa-arrows"></i></span> <strong class="df-item-label">' + escapeHtml(item.label) + '</strong><span class="text-muted pull-right">' + escapeHtml(item.type) + ' <i class="fa fa-caret-down js-toggle-item"></i></span></div>' +
                '<div class="df-item-settings" style="display:none">' +
                '<div class="form-group"><label>Navigation Label</label><input type="text" class="form-control js-item-title" value="' + escapeHtml(item.label) + '"></div>' +
                '<div class="form-group"><label>URL</label><input type="text" class="form-control js-item-url" value="' + escapeHtml(item.url || '') + '"' + readonly + '></div>' +
                '<div class="form-group"><label>CSS Class</label><input type="text" class="form-control js-item-class" value=""></div>' +
                '<div class="form-group">' +
                '<label>Attributes JSON</label>' +
                '<textarea class="form-control input-sm js-item-attribute" rows="3" placeholder=\'{"rel":"nofollow","aria-label":"Home"}\'>' +
                escapeHtml(item.item_attribute || '') +
                '</textarea>' +
                '<p class="help-block">Optional JSON attributes for the rendered link.</p>' +
                '</div>' +
                '<label><input type="checkbox" class="js-item-window" ' + (item.new_window ? 'checked' : '') + '> Open in new window</label>' +
                '<div class="df-item-actions"><button type="button" class="btn btn-xs btn-primary js-update-item">Save Item</button> <button type="button" class="btn btn-xs btn-danger js-remove-item">Remove</button></div>' +
                '</div><ol class="df-menu-list df-menu-children"></ol></li>';
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>'"]/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
            });
        }

        function addItem(payload, done) {
            payload.menu_id = menuId;

            post('plugin/menu-builder/add-menu-item', payload, function (res) {
                var item = $.extend({}, payload, {
                    id: res.id,
                    label: payload.custom_label || payload.display_label || '',
                    url: payload.custom_url || payload.display_url || '',
                    item_attribute: payload.item_attribute || '',
                    item_css_class: payload.item_css_class || ''
                });

                if (done) {
                    done(item);
                    return;
                }

                $('.sortable-root').append(itemHtml(item));
                initDragDrop();
                toast('Menu item added.');
            });
        }

        function promptModal(options, callback) {
            var $modal = $('#dfPromptModal');
            var $input = $modal.find('.js-prompt-input');

            $modal.find('.js-prompt-title').text(options.title || 'Prompt');
            $modal.find('.js-prompt-label').text(options.label || 'Value');
            $input.val(options.value || '');

            $modal.off('shown.bs.modal').on('shown.bs.modal', function () {
                $input.focus().select();
            });

            $modal.find('.js-prompt-confirm').off('click').on('click', function () {
                var value = $.trim($input.val());

                if (!value) {
                    toast(options.requiredMessage || 'This field is required.', 'error');
                    return;
                }

                $modal.modal('hide');
                callback(value);
            });

            $modal.modal('show');
        }

        function confirmModal(options, callback) {
            var $modal = $('#dfConfirmModal');

            $modal.find('.js-confirm-title').text(options.title || 'Confirm');
            $modal.find('.js-confirm-message').text(options.message || 'Are you sure?');

            $modal.find('.js-confirm-confirm')
                .removeClass('btn-danger btn-primary btn-warning')
                .addClass(options.buttonClass || 'btn-danger')
                .text(options.buttonText || 'Confirm')
                .off('click')
                .on('click', function () {
                    $modal.modal('hide');
                    callback();
                });

            $modal.modal('show');
        }

        function parseAttributes(value) {
            value = $.trim(value || '');

            if (!value) {
                return {};
            }

            try {
                var parsed = JSON.parse(value);

                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (e) {
                return null;
            }

            return null;
        }

        function stringifyAttributes(attributes) {
            if (!attributes || Object.keys(attributes).length === 0) {
                return '';
            }

            return JSON.stringify(attributes, null, 4);
        }

        function syncNewWindowAttributes($item) {
            var $attribute = $item.find('> .df-item-settings .js-item-attribute');
            var opensNewWindow = $item.find('> .df-item-settings .js-item-window').is(':checked');

            var attributes = parseAttributes($attribute.val());

            if (attributes === null) {
                toast('Attributes must be valid JSON before saving.', 'error');
                return false;
            }

            if (opensNewWindow) {
                attributes.target = '_blank';

                var rel = $.trim(attributes.rel || '');
                var relParts = rel ? rel.split(/\s+/) : [];

                if (relParts.indexOf('noopener') === -1) {
                    relParts.push('noopener');
                }

                if (relParts.indexOf('noreferrer') === -1) {
                    relParts.push('noreferrer');
                }

                attributes.rel = relParts.join(' ');
            } else {
                delete attributes.target;

                if (attributes.rel) {
                    var filteredRel = String(attributes.rel)
                        .split(/\s+/)
                        .filter(function (part) {
                            return part !== 'noopener' && part !== 'noreferrer';
                        });

                    if (filteredRel.length) {
                        attributes.rel = filteredRel.join(' ');
                    } else {
                        delete attributes.rel;
                    }
                }
            }

            $attribute.val(stringifyAttributes(attributes));

            return true;
        }

        function syncCssClassAttribute($item) {
            var $cssClass = $item.find('> .df-item-settings .js-item-class');
            var $attribute = $item.find('> .df-item-settings .js-item-attribute');

            var attributes = parseAttributes($attribute.val());

            if (attributes === null) {
                toast('Attributes must be valid JSON before saving.', 'error');
                return false;
            }

            delete attributes.class;

            $attribute.val(stringifyAttributes(attributes));

            return true;
        }

        $(document).on('click', '.js-toggle-item', function () {
            $(this).closest('.df-menu-item').children('.df-item-settings').slideToggle(120);
        });

        $(document).on('click', '.js-add-source', function () {
            var $source = $(this).closest('.df-source-item');
            addItem({
                type: $source.data('type'),
                object_id: $source.data('object-id'),
                object_type: $source.data('object-type'),
                display_label: $source.data('label'),
                display_url: $source.data('url'),
                new_window: 0
            });
        });

        $(document).on('click', '.js-add-checked', function () {
            $(this).closest('.tab-pane').find('.js-source-check:checked').each(function () {
                $(this).closest('.df-source-item').find('.js-add-source').trigger('click');
                this.checked = false;
            });
        });

        $(document).on('click', '.js-add-custom', function () {
            var label = $('.js-custom-label').val();
            var url = $('.js-custom-url').val();
            if (!label || !url) {
                toast('Custom links need both a label and URL.', 'error');
                return;
            }
            addItem({ type: 'custom', custom_label: label, custom_url: url, new_window: $('.js-custom-window').is(':checked') ? 1 : 0 });
            $('.js-custom-label,.js-custom-url').val('');
            $('.js-custom-window').prop('checked', false);
        });

        $(document).on('click', '.js-update-item', function () {
            var $item = $(this).closest('.df-menu-item');

            if (!syncNewWindowAttributes($item)) {
                return;
            }

            if (!syncCssClassAttribute($item)) {
                return;
            }

            post('plugin/menu-builder/update-menu-item', {
                item_id: $item.data('id'),
                custom_label: $item.find('> .df-item-settings .js-item-title').val(),
                custom_url: $item.find('> .df-item-settings .js-item-url').val(),
                css_class: $item.find('> .df-item-settings .js-item-class').val(),
                new_window: $item.find('> .df-item-settings .js-item-window').is(':checked') ? 1 : 0,
                attribute: $item.find('> .df-item-settings .js-item-attribute').val()
            }, function () {
                $item.find('> .df-menu-bar .df-item-label').text($item.find('> .df-item-settings .js-item-title').val());
                toast('Menu item updated.');
            });
        });

        $(document).on('click', '.js-remove-item', function () {
            var $item = $(this).closest('.df-menu-item');

            confirmModal({
                title: 'Remove Menu Item',
                message: 'Remove this menu item and its children?',
                buttonText: 'Remove Item',
                buttonClass: 'btn-danger'
            }, function () {
                post('plugin/menu-builder/remove-menu-item', { item_id: $item.data('id') }, function () {
                    $item.remove();
                    toast('Menu item removed.');
                });
            });
        });

        $(document).on('click', '.js-create-menu', function () {
            promptModal({
                title: 'Create Menu',
                label: 'Menu Name',
                value: 'Header Menu',
                requiredMessage: 'Please enter a menu name.'
            }, function (name) {
                post('plugin/menu-builder/create-menu', { name: name }, function (res) {
                    window.location = adminUrl('plugin/menu-builder/?menu=' + encodeURIComponent(res.id));
                });
            });
        });

        $(document).on('click', '.js-rename-menu', function () {
            post('plugin/menu-builder/update-menu', { menu_id: menuId, name: $('.js-menu-name').val() }, function () {
                toast('Menu renamed.');
            });
        });

        $(document).on('click', '.js-delete-menu', function () {
            confirmModal({
                title: 'Delete Menu',
                message: 'Delete this menu and all of its menu items?',
                buttonText: 'Delete Menu',
                buttonClass: 'btn-danger'
            }, function () {
                post('plugin/menu-builder/delete-menu', { menu_id: menuId }, function () {
                    window.location = adminUrl('plugin/menu-builder/');
                });
            });
        });

        function serialize($list, parentId, output) {
            $list.children('.df-menu-item').each(function (index) {
                var id = $(this).data('id');
                output.push({ id: id, parent_id: parentId || null, position: index });
                serialize($(this).children('.df-menu-list'), id, output);
            });
            return output;
        }

        $(document).on('click', '.js-save-order', function () {
            post('plugin/menu-builder/reorder', { menu_id: menuId, items: JSON.stringify(serialize($('.sortable-root'), null, [])) }, function () {
                toast('Menu order saved.');
            });
        });
    })(jQuery);
