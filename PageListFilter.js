function ProcessPageListFilter() {
	
	var lastFilter = '';
	var lastPageId = 0;
	
	$(document).on('click', '.PageListActionFilter a', function(e) {
		
		var a = $(this);
		var li = a.parent();
		var filter = a.text();
		
		li.siblings().removeClass('PageListActionFilterActive');
		li.addClass('PageListActionFilterActive');
		
		var pageListItem = $(this).closest('.PageListItem');
		var actions = pageListItem.find('.PageListActions');
		var pageId = pageListItem.data('pageId');
		var spinner = $('.PageListLoading');
		
		if(filter === 'All') {
			if(pageListItem.hasClass('PageListItemOpen')) {
				pageListItem.next('.PageList').remove();
				pageListItem.removeClass('PageListItemOpen');
			}
			pageListItem.children('a.PageListPage').trigger('click');
			return false;
		}
		
		lastPageId = pageId;
		lastFilter = filter;
		
		var pageList = pageListItem.next('.PageList');
		var pageListItemRefresh = pageList.find('.PageListItem:first').eq(0);
		var refreshPageId = pageListItemRefresh.data('pageId');
		
		if(pageList.length) {
			pageList.children('.PageListPagination, .uk-pagination').remove();
		} else {
			pageList = $('<div></div>').addClass('PageList');
			pageList.insertAfter(pageListItem);
			// the following makes ProcessPageList.js use this container rather than its own
			$('<div />').addClass('PageListID' + refreshPageId).appendTo(pageList);
		}
		
		pageListItem.append(spinner.fadeIn());
		pageList.data('lastFilter', lastFilter);
		pageList.data('lastPageId', lastPageId);
		actions.addClass('PageListActionsKeepOpen');
		pageList.hide();
		
		var filterAll = actions.find('.FilterAll');
		filterAll.data('markup', filterAll.html());
		filterAll.html('<a>' + spinner.html() + '</a>');
		filterAll.find('i').addClass('fa-fw');
		
		$(document).trigger('pageListRefresh', refreshPageId);
		
		$(document).one('pageListChildrenDone', function(e, data) {
			var item = data.item;
			var actions = item.find('.PageListActions');
			var more = pageList.find('.PageListActionMore');
			if(more.length) {
				more.addClass('PageListActionMoreFilter')
					.attr('data-lastPageId', pageList.data('lastPageId'))
					.attr('data-lastFilter', pageList.data('lastFilter'));
				more.on('mousedown', function() {
					lastPageId = $(this).attr('data-lastPageId');
					lastFilter = $(this).attr('data-lastFilter');
				});
			}
			
			actions.addClass('PageListActionsKeepOpen');
			pageListItem.addClass('PageListItemOpen');
			pageList.slideDown('fast');
			filterAll.html(filterAll.data('markup'));
		});
		
		return false;
	});
	
	$(document).on('ajaxSend', function(event, jqxhr, settings) {
		if(!lastFilter.length) return;
		if(!lastPageId) return;
		if(settings.url.indexOf('/page/list/') === -1) return;
		if(settings.url.indexOf('render=JSON') === -1) return;
		if(settings.url.indexOf('?id=' + lastPageId + '&') === -1) return;
		settings.url += '&filter=' + lastFilter; 
		lastFilter = '';
		lastPageId = 0;
	});
}

$(document).ready(function() {
	ProcessPageListFilter();
});