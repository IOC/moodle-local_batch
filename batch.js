// Local batch plugin for Moodle
// Copyright © 2012,2013 Institut Obert de Catalunya
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// Ths program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

YUI(M.yui.loader).use('node', 'anim', function(Y) {

    var init = function() {
        var node = Y.one('#course-list tbody tr:first-child a.delete-course');
        if (node) {
            node.setStyle('visibility', 'hidden');
        }
        Y.all('.js-only').removeClass('js-only');
        var nodes = Y.all('.categories li.category_group');
        if (nodes) {
            nodes.addClass('batch_hidden');
        }
    }
    var batch_delete_row = function(node) {
        if (Y.all('#course-list tbody tr').size() > 1) {
            node.ancestor('tr').remove();
            var obj = Y.one('#course-list tbody tr:last-child input[name^="shortname-"]');
            var lastindex = obj.getAttribute('name').match(/\d+/);
            Y.one('input[name="lastindex"]').setAttribute('value', lastindex[0]);
            var obj = Y.one('#course-list tbody tr:last-child');
            if (obj) {
                obj.addClass('lastrow');
            }
        }
    };

    var batch_add_row = function() {
        var course_row = Y.one('#course-list tbody tr:last-child').cloneNode(true);
        var node_lastindex = Y.one('input[name="lastindex"]');
        var lastindex = parseInt(node_lastindex.getAttribute('value'), 10);
        course_row.one('input[name="shortname-'+lastindex+'"]').set('name', 'shortname-'+(lastindex+1)).set('value', '');
        course_row.one('input[name="fullname-'+lastindex+'"]').set('name', 'fullname-'+(lastindex+1)).set('value', '');
        course_row.one('#category-'+lastindex).set('id', 'category-'+(lastindex+1)).set('name', 'category-'+(lastindex+1));
        node_lastindex.setAttribute('value', (lastindex+1));
        var node = Y.one('#course-list tbody').append(course_row);
        Y.one("#course-list tbody tr:last-child a.delete-course").setStyle('visibility', 'visible');
        Y.one('#course-list tbody tr:last-child input[name^="shortname"]').focus();
        var count = Y.all('#course-list tbody tr').size();
        var obj = Y.all('#course-list tbody tr').item(count-2);
        if (obj) {
            obj.removeClass('lastrow');
        }
    };

    var batch_toggle_datepicker = function() {
        batch_position_datepicker();
        var node = Y.one('#dateselector-calendar-panel');
        if (M.form.dateselector.showing) {
            M.form.dateselector.panel.hide();
            M.form.dateselector.showing = false;
        } else {
            M.form.dateselector.panel.show();
            M.form.dateselector.showing = true;
        }
    };

    var batch_position_datepicker = function() {
        var datepicker = Y.one('#dateselector-calendar-panel');
        var startdate = Y.one('#startdate');
        var position = startdate.getXY();
        position[1] -= datepicker.get('offsetHeight');
        datepicker.setXY(position);
    };

    var batch_get_selected_date = function(cell, data) {
        var node = Y.one('input[name="startdate"]');
        node.set('value', cell.date.getDate() + '/' + (cell.date.getMonth()+1) + '/' + cell.date.getFullYear());
        M.form.dateselector.showing = false;
        M.form.dateselector.panel.hide();
    };

    Y.on('change', function(e) {
        var form = Y.one('#queue-filter');
        form.submit();
    }, '#local_batch_filter');

    if (Y.one("#course-list")) {
        Y.one("#course-list").delegate('click', function(e) {
            e.preventDefault();
            batch_delete_row(this);
        }, 'a.delete-course');
    }

    Y.on('click', function(e) {
        e.preventDefault();
        batch_add_row();
    }, '#add-course');

    Y.on('click', function(e) {
        if (this.get('checked')) {
            Y.one('#prefix').setAttribute('disabled', 'disabled');
        } else {
            Y.one('#prefix').removeAttribute('disabled');
        }
    }, '#remove_prefix');

    Y.on('click', function(e) {
        M.form.dateselector.showing = false;
        M.form.dateselector.panel.hide();
    }, '#page');

    Y.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        batch_toggle_datepicker();
    }, '#batch_toggle_datepicker');

    Y.on('contentready', function() {
        if (M.form.dateselector.calendar) {
            M.form.dateselector.calendar.on('dateClick', batch_get_selected_date);
            M.form.dateselector.panel.set('zIndex', 1);
            Y.one('#dateselector-calendar-panel')
                .setStyle('border', 0)
                .setStyle('margin', '8px 0 0 3px')
                .setStyle('background-color', 'transparent')
                .setStyle('min-height', '210px');
            M.form.dateselector.calendar.render();
        }
    }, '#dateselector-calendar-panel');

    if (Y.one('.category_group')) {
        Y.one('#course-tree').delegate('click', function(e) {
            e.stopPropagation();
            this.ancestor('li').toggleClass('batch_hidden');
            this.next('img.batch_toggle_category').toggleClass('batch_hidden_toggle');
        }, '.category_group span');

        Y.one('#course-tree').delegate('click', function(e) {
            e.stopPropagation();
            Y.all('.category_group').addClass('batch_hidden');
        }, '.expandall');
        Y.one('#course-tree').delegate('click', function(e) {
            e.stopPropagation();
            Y.all('.category_group').removeClass('batch_hidden');
        }, '.collapseall');
    }

    if (Y.one('#course-tree')) {
        Y.one('#course-tree').delegate('click', function(e) {
            e.stopPropagation();
            var nodes = this.next('ul.courses').all('input');
            if (nodes.size() > nodes.get('checked').filter(function(i){return i;}).length) {
                nodes.set('checked', 'checked');
            } else {
                nodes.set('checked', '');
            }
        }, '.batch_toggle_category');
    }

    if (Y.one('.batch_error')) {
        Y.all('.batch_error').addClass('batch_collapsed');
        Y.one('#page-content').delegate('click', function(e) {
            this.toggleClass('batch_collapsed');
            this.one('.batch_error_switcher').toggleClass('batch_error_switcher_minus');
        }, '.batch_error');
    }

    init();
});
