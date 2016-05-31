<?php
namespace Celebros\ConversionPro\Helper;

use Magento\Framework\App\Helper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Catalog\Model\Category;
use Celebros\ConversionPro\Model\Config\Source\CategoryQueryType;

class Search extends Helper\AbstractHelper
{
    const CATEGORY_QUESTION_TEXT = 'Category';
    const CAT_ID_DYN_PROPERTY = 'MagEntityID';
    const CACHE_TAG = 'CONVERSIONPRO';
    const CACHE_ID = 'conversionpro';
    const CACHE_LIFETIME = 13600;
    
    /**
     * @var Data
     */
    protected $helper;
    
    protected $customResultsCache = [];
    protected $allQuestionsCache;
    protected $questionAnswers = [];
    
    protected $currentPage;
    protected $pageSize;
    protected $order;
    protected $cache;
    protected $cacheState;
    protected $category;
    
    /**
     * @var \Celebros\ConversionPro\Model\Search
     */
    protected $search;
    
    public function __construct(
        Helper\Context $context,
        Data $helper,
        \Magento\Framework\App\Cache $cache,
        \Magento\Framework\App\Cache\State $cacheState,
        \Celebros\ConversionPro\Model\Search $search,
        \Magento\Catalog\Model\Category $category
    ) {
        $this->helper = $helper;
        $this->search = $search;
        $this->cache = $cache;
        $this->cacheState = $cacheState;
        $this->category = $category;
        parent::__construct($context);
    }
    
    public function getSearchParams()
    {
        $request = $this->_getRequest();
        $params = new DataObject();
        
        $queryText = '';
        
        // search query text
        if ($request->getParam('q'))
            $queryText = $request->getParam('q');
            
        // category query text
        $category = $this->helper->getCurrentCategory();
        if ($category && $category->getId() != $this->helper->getCurrentStore()->getRootCategoryId())
        {
            if (!$this->helper->isTextualNav2Search()) {
                $queryText = '';
            } else {
                $queryText = $this->getCategoryQueryTerm($category);
            }
        }
      
        $params->setQuery($queryText);
        
        // filters
        $filters = [];
        foreach ($this->getFilterRequestVars() as $requestVar) {
            $value = $this->getFilterValueAsArray($requestVar);
            if (!empty($value))
                $filters[$requestVar] = $value;
        }
        
        if ($category && $category->getId() != $this->helper->getCurrentStore()->getRootCategoryId() && !$this->helper->isTextualNav2Search()) {
            $filters[self::CATEGORY_QUESTION_TEXT][] = $this->getAnswerIdByCategoryId($category);
        }
        
        $params->setFilters($filters);
        
        return $params;
    }
    
    public function getCustomResults(DataObject $params = null)
    {
        $params = is_null($params) ? $this->getSearchParams() : clone $params;
        // order
        if (!is_null($this->order) && !$params->hasSortBy())
            $params->setSortBy($this->order);
        // page size
        if (!is_null($this->pageSize) && !$params->hasPageSize())
            $params->setPageSize($this->pageSize);
        // current page
        if (!is_null($this->currentPage) && !$params->hasCurrentPage())
            $params->setCurrentPage($this->currentPage - 1);
        
        $searchHandle = $this->search->createSearchHandle($params);
        if (!isset($this->customResultsCache[$searchHandle])) {
            $this->customResultsCache[$searchHandle] = 
                $this->search->getCustomResults($searchHandle, true, '');
        }
        return $this->customResultsCache[$searchHandle];
    }
    
    public function getAllQuestions()
    {
        if (is_null($this->allQuestionsCache)) {
            //$cacheId = $this->getCacheId(__METHOD__);
            //if ($allQuestions = $this->loadCache($cacheId)) {
            //    $this->allQuestionsCache = unserialize($allQuestions);
            //} else {
                $this->allQuestionsCache = $this->search->getAllQuestions();
            //    $this->saveCache(serialize($this->allQuestionsCache), $cacheId);
            //}
        }
        
        return $this->allQuestionsCache;
    }
    
    public function getQuestionAnswers($questionId)
    {
        if (!isset($this->questionAnswers[$questionId])) {
            $this->questionAnswers[$questionId] =
                $this->search->getQuestionAnswers($questionId);
        }
        return $this->questionAnswers[$questionId];
    }
    
    public function getCategoryQueryTerm(Category $category, $store = null)
    {
        $queryType = $this->helper->getCategoryQueryType($store);
        if ($queryType == CategoryQueryType::NAME)
            return $category->getName();
            
        $parents = $category->getParentCategories();
        $parentIds = array_intersect($category->getParentIds(), array_keys($parents));
        switch ($queryType) {
            case CategoryQueryType::FULL_PATH:
                break;
            case CategoryQueryType::NAME_AND_PARENT_NAME:
                $parentId = $category->getParentId();
                $parentIds = in_array($parentId, $parentIds) ? [$parentId] : [];
                break;
            case CategoryQueryType::NAME_AND_ROOT_NAME:
                $parentIds = array_slice($parentIds, 0, 1);
                break;
        }
        
        $names = array_map(
            function ($id) use ($parents) {
                return $parents[$id]->getName();
            },
            $parentIds);
        $names[] = $category->getName();
        
        return implode(' ', $names);
    }
    
    public function getFilterValue($requestVar)
    {
        $filterRequestVars  = $this->getFilterRequestVars();
        $value = $this->_getRequest()->getParam($requestVar);
        if (!is_null($value) && !$this->helper->isMultiselectEnabled()) {
            $values = $this->filterValueToArray($value);
            $value = $values[0];
        }
        return $value;
    }
    
    public function getFilterValueAsArray($requestVar)
    {
        $value = $this->getFilterValue($requestVar);
        return is_null($value) ? [] : $this->filterValueToArray($value);
    }
    
    public function filterValueToArray($value)
    {
        return explode(',', $value);
    }
    
    public function getFilterRequestVars()
    {
        $questions = $this->getAllQuestions();
        $names = ['price'];
        foreach ($questions->Questions->children() as $question)
            $names[] = $question->getAttribute('Text');
        return $names;
    }
    
    public function getLabelByAnswerId($answerId)
    {
        return $this->questionAnswers;
    }
    
    public function setCurrentPage($page)
    {
        $this->currentPage = $page;
        return $this;
    }
    
    public function setPageSize($size)
    {
        $this->pageSize = $size;
        return $this;
    }
    
    public function setOrder($order, $dir)
    {
        $this->order = [$order, $dir];
        return $this;
    }
    
    public function getCurrentCustomResults($handle = NULL)
    {
        if ($handle) {
            if (isset($this->customResultsCache[$hanlde])) {
                return $this->customResultsCache[$hanlde];
            }
        }
        
        return reset($this->customResultsCache);    
    }
    
    public function getAnswerIdByCategoryId($category)
    {
        $cacheId = $this->getCacheId(__METHOD__, array($category->getId()));
        if ($answerId = $this->loadCache($cacheId)) {
            return $answerId;    
        }
        
        $allQuestions = $this->getAllQuestions()->Questions->Question;
        foreach ($allQuestions as $question) {
            if ($question->getAttribute('Text') == self::CATEGORY_QUESTION_TEXT) {
                $catQuestionId = (int)$question->getAttribute('Id');
                continue;
            }
        }
        
        if (isset($catQuestionId)) {
            $catLabel = $category->getName();
            $answers = $this->getQuestionAnswers($catQuestionId);
            foreach ($answers->Answers->Answer as $answer) {
                foreach ($answer->DynamicProperties->children() as $property) {
                    if ($property->getAttribute('name') == self::CAT_ID_DYN_PROPERTY) {
                        if ($property->getAttribute('value') == $category->getId()) {
                            $this->saveCache($answer->getAttribute('Id'), $cacheId);
                            return (int)$answer->getAttribute('Id'); 
                        }
                    }
                }
                
                /* try to find category by label */
                if ($answer->getAttribute('Text') == $catLabel) {
                    $this->saveCache($answer->getAttribute('Id'), $cacheId);
                    return (int)$answer->getAttribute('Id');
                }
            }
        }
        
        return FALSE;
    }
    
    public function getCacheId($method, $vars = array())
    {
        return base64_encode($method . implode('', $vars));
    }
    
    public function loadCache($cacheId)
    {
        if ($this->cacheState->isEnabled(self::CACHE_ID)) { 
            return $this->cache->load($cacheId);
        }
        
        return FALSE;
    }
    
    public function saveCache($data, $cacheId)
    {
        if ($this->cacheState->isEnabled(self::CACHE_ID)) { 
            $this->cache->save($data, $cacheId, array(self::CACHE_TAG), self::CACHE_LIFETIME);
            return TRUE;
        }
        
        return FALSE;
    }
}