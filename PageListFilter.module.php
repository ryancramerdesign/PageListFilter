<?php namespace ProcessWire;

/**
 * Page List Filter for ProcessWire
 * 
 * Admin helper that enables you to easily filter pages in the page list with a click, 
 * such as first letter (A-Z, etc.).
 * 
 * Requires ProcessWire 3.0.245 or newer
 * License MPL 2.0
 * 
 * ProcessWire 3.x, Copyright 2025 by Ryan Cramer
 * https://processwire.com
 * 
 * @property string $useSelectors
 * 
 */
class PageListFilter extends WireData implements Module, ConfigurableModule {

	/**
	 * Runtime caches used by methods in this module
	 * 
	 * @var array 
	 * 
	 */
	protected array $caches = [];

	/**
	 * Construct
	 * 
	 */
	public function __construct() {
		parent::__construct();
		$this->set('useSelectors', '');
	}

	/**
	 * API ready
	 * 
	 */
	public function ready() {
		$config = $this->wire()->config;
		
		$this->addHookAfter('ProcessPageList::getSelector', $this, 'hookGetSelector');
		$this->addHookAfter('ProcessPageListRender::getPageActions', $this, 'hookGetPageActions');
		$this->addHookBefore('ProcessPageListRender::getNumChildren', $this, 'hookGetNumChildren');
		
		// hookBeforeExecute for debugging pagination only
		// $this->addHookBefore('ProcessPageList::execute', $this, 'hookBeforeExecute'); 
		
		if(!$config->ajax) {
			$config->scripts->add($config->urls($this) . $this->className() . '.js');
			$config->styles->add($config->urls($this) . $this->className() . '.css');
		}
	}

	/**
	 * Get or set from caches
	 * 
	 * @param string $name
	 * @param Page|int $page
	 * @param string|array|null $value
	 * @return string|array
	 * 
	 */
	protected function caches(string $name, $page, $value = null) {
		$key = "p$page";
		if($value === null) return $this->caches[$key][$name] ?? null;
		if(!isset($this->caches[$key])) $this->caches[$key] = [];
		$this->caches[$key][$name] = $value;
		return $value;
	}
		
	/**
	 * Get all child prefixes for given parent page
	 * 
	 * @param Page $parent
	 * @param string $sortfield
	 * @return array
	 * 
	 */
	protected function getPrefixes(Page $parent, string $sortfield = ''): array {
	
		if(empty($sortfield)) $sortfield = $this->getPageSortfield($parent);
		$field = $sortfield === 'name' ? 'name' : $this->wire()->fields->get($sortfield);
		if(!$field) return [];
		
		$prefixes = $this->caches('prefixes', $parent); 
		if(is_array($prefixes)) return $prefixes;
		
		$prefixes = [];
		$sql = [];
		$table = $field instanceof Field ? $field->getTable() : 'pages';

		if($field === 'name') {
			$languages = $this->wire()->languages;
			if($languages && $languages->hasPageNames()) {
				$lid = $this->wire()->user->language->id;
				$sql[] = "SELECT SUBSTRING(pages.name, 1, 1) AS prefix1, SUBSTRING(pages.name$lid, 0, 1) AS prefix2";
			} else {
				$sql[] = "SELECT DISTINCT SUBSTRING(pages.name, 1, 1) AS prefix";
			}
			
		} else if($field->type instanceof FieldtypeText) {
			$sql[] = "SELECT DISTINCT SUBSTRING($table.data, 1, 1) AS prefix";
			
		} else if($this->wire()->languages && wireInstanceOf($field->type, 'FieldtypeTextLanguage')) {
			$lid = $this->wire()->user->language->id;
			$sql[] = "SELECT SUBSTRING($table.data, 1, 1) AS prefix1, SUBSTRING($table.data$lid, 0, 1) AS prefix2";
		}
		
		if($sql) {
			$sql[] = "FROM pages";
			if($field instanceof Field) $sql[] = "JOIN $table ON $table.pages_id=pages.id";
			$sql[] = "WHERE pages.parent_id=:id";

			$query = $this->wire()->database->prepare(implode(' ', $sql));
			$query->bindValue(':id', $parent->id, \PDO::PARAM_INT);
			$query->execute();

			while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
				if(array_key_exists('prefix1', $row)) {
					// multi-language
					$prefix = empty($row['prefix2']) ? "$row[prefix1]" : "$row[prefix2]";
				} else {
					$prefix = "$row[prefix]";
				}
				if(!strlen($prefix)) continue;
				$prefix = mb_strtoupper($prefix);
				$prefixes[$prefix] = $prefix;
			}
			
			$query->closeCursor();
			
			ksort($prefixes);
		}
		
		$this->caches('prefixes', $parent, $prefixes);
		
		return $prefixes;
	}

	/**
	 * Get filters to use for given parent page
	 * 
	 * Optionally omit parent page
	 * 
	 * @param Page $page
	 * @return array
	 * 
	 */
	protected function getPageListFilters(Page $page): array {
		
		$prefixes = [];
		$sortfield = $page ? $this->getPageSortfield($page) : '';
		$allPrefix = 'All';
		
		if(empty($sortfield)) return [];
	
		$filters = $this->caches('filters', $page); 
		if(is_array($filters)) return $filters;
		
		$filters = [];

		if(!$this->pageMatchesSelector($page)) return [];

		if($page->numChildren) {
			$prefixes = $this->getPrefixes($page, $sortfield);
		}

		if(count($prefixes)) {
			$pages = $this->wire()->pages;
			array_unshift($prefixes, $allPrefix);

			foreach($prefixes as $prefix) {
				if($prefix === $allPrefix) {
					$numChildren = 1;
				} else if($sortfield) {
					$selector = "parent_id=$page->id, $sortfield^=$prefix, include=unpublished";
					$numChildren = $pages->count($selector);
					if(!$numChildren) continue;
				} else {
					$numChildren = 1;
				}
				$filters[$prefix] = [
					'prefix' => $prefix,
					'numChildren' => $numChildren
				];
			}
		}
	
		$this->caches('filters', $page, $filters);

		return $filters;
	}

	/**
	 * Get the filter requested in the URL
	 * 
	 * @param Page|null $page
	 * @return string
	 * 
	 */
	protected function getRequestFilter(?Page $page = null): string {
		
		$filter = $this->caches('requestFilter', 0);
		if($filter !== null) return $filter;
		
		$input = $this->wire()->input;
		$filter = (string) $input->get('filter');
		
		if(!strlen($filter) || mb_strlen($filter) > 1) {
			$filter = '';
		} else if(!ctype_alnum($filter)) {
			$filter = '';
		} else if(empty($page)) {
			$id = (int) $input->get('id');
			if($id < 1) {
				$filter = '';
			} else {
				$page = $this->wire()->pages->get($id);
			}
		}
		
		if(strlen($filter) && $page && $page->id) {
			$filters = $this->getPageListFilters($page);
			if(!isset($filters[$filter])) {
				$filter = '';
			}
		} else {
			$filter = '';
		}
		
		$this->caches('requestFilter', 0, $filter);
		
		return $filter;
	}

	/**
	 * Get number of children for page
	 * 
	 * @param Page $page
	 * @return int
	 * 
	 */
	protected function getNumChildren(Page $page): int {
		$filter = $this->getRequestFilter();
		if(empty($filter)) return $page->numChildren;
		$sortfield = $this->getPageSortfield($page);
		if(empty($sortfield)) return $page->numChildren;
		$selector = "parent_id=$page, $sortfield^=$filter, include=unpublished";
		return $this->wire()->pages->count($selector);
	}

	/**
	 * Does given page match one of the configured selectors? 
	 * 
	 * @param Page $page
	 * @return string Returns matching selecgtor string or blank string if no match
	 * 
	 */
	protected function pageMatchesSelector(Page $page): string {

		$useSelectors = $this->useSelectors;
		if(empty($useSelectors)) return '';
	
		$selector = $this->caches('selector', $page);
		if($selector !== null) return $selector;
		$selector = '';
	
		if($page->numChildren) {
			foreach(explode("\n", $useSelectors) as $s) {
				if(strpos($s, 'id=') === 0 && strpos($s, ',') === false) {
					list(, $id) = explode('=', $s, 2); 
					if($page->id === (int) $id) $selector = $s;	
				} else {
					if($page->matches($s)) $selector = $s;
				}
				if($selector) break;
			}
		}
		
		$this->caches('selector', $page, $selector);
		
		return $selector;
	}

	/**
	 * Get sortfield for given page
	 * 
	 * @param Page $page
	 * @return string
	 * 
	 */
	public function getPageSortfield(Page $page):string {
		$sortfield = ltrim($page->sortfield(), '-');
		if(empty($sortfield) || $sortfield === 'sort') return '';
		return $sortfield;
	}

	/*** HOOK METHODS **********************************************************/

	/**
	 * Hook after ProcessPageList::getSelector()
	 * 
	 * @param HookEvent $event
	 * 
	 */	
	public function hookGetSelector(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$filter = $this->getRequestFilter();
		if($filter !== '' && $filter !== 'All' && $filter !== '*') {
			$sortfield = $this->getPageSortfield($page);
			if($sortfield) $event->return .= ", $sortfield^=$filter"; 
		}
	}

	/**
	 * Hook after ProcessPageListRender::getPageActions()
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookGetPageActions(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$filters = $this->getPageListFilters($page);
		if(!count($filters)) return;
		$actions = $event->return; /** @var array $actions */

		foreach($filters as $filter) {
			$prefix = $filter['prefix'];
			$name = $prefix;
			if($name === 'All') $name = wireIconMarkup('sort-alpha-asc', 'fw'); // "<i class='fa fa-sort-alpha-asc fa-fw'></i>";
			$actions["filter-$prefix"] = [
				'cn' => "Filter Filter$prefix",
				'name' => $name,
				'url' => "./?id=$page&render=JSON&start=0&filter=$prefix",
			];
		}

		$event->return = $actions;
	}

	/**
	 * Hook before ProcessPageListActions::getNumChildren()
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookGetNumChildren(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		if(!$this->pageMatchesSelector($page)) return;
		$event->return = $this->getNumChildren($page);
		$event->replace = true;
	}

	public function hookBeforeExecute(HookEvent $event) {
		$ppl = $event->object; /** @var ProcessPageList $ppl */
		$ppl->limit = 10; // For pagination testing purposes only
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$f = $inputfields->InputfieldTextarea;
		$f->attr('name', 'useSelectors'); 
		$f->label = $this->_('Enter one per line of selectors to match parent pages');
		$f->description = 
			$this->_('Matching parent pages will show filters for their children in the page list.') . ' ' . 
			$this->_('Parents should use a “sortfield” that visually matches the labels of its children.') . ' ' . 
			$this->_('For example, if pages are sorted by “title” then the page list label used by its children should also be “title”, or at least ideally start with it.') . ' ' . 
			$this->_('This module is not applicable to manually sorted pages.');
		$f->notes = $this->_('Examples: `id=1234` (best performance), or `name=events`, or `template=products`, etc.');
		$f->val($this->useSelectors);
		$inputfields->add($f);
	}

}	