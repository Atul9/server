<?php

/**
 * Subclass for representing a row from the 'category' table.
 *
 * 
 *
 * @package Core
 * @subpackage model
 */ 
class category extends Basecategory implements IIndexable
{
	protected $childs_for_save = array();
	
	protected $depth = 0;
	
	protected $parent_category;
	
	protected $old_full_name = "";
	
	protected $old_parent_id = null;
	
	const MAX_CATEGORY_DEPTH = 8;
	
	const CATEGORY_ID_THAT_DOES_NOT_EXIST = 0;
	
	private static $indexFieldTypes = array(
		'category_id' => IIndexable::FIELD_TYPE_INTEGER,
		'partner_id' => IIndexable::FIELD_TYPE_INTEGER,
		'name' => IIndexable::FIELD_TYPE_STRING,
		'full_name' => IIndexable::FIELD_TYPE_STRING,
		'description' => IIndexable::FIELD_TYPE_STRING,
		'tags' => IIndexable::FIELD_TYPE_STRING,
		'category_status' => IIndexable::FIELD_TYPE_INTEGER,
		'kuser_id' => IIndexable::FIELD_TYPE_INTEGER,
		'display_in_search' => IIndexable::FIELD_TYPE_INTEGER,
		'search_text' => IIndexable::FIELD_TYPE_STRING,
		'members' => IIndexable::FIELD_TYPE_STRING
	);
	
	public function save(PropelPDO $con = null)
	{
		if ($this->isNew())
		{
			$numOfCatsForPartner = categoryPeer::doCount(new Criteria());
			
			if ($numOfCatsForPartner >= Partner::MAX_NUMBER_OF_CATEGORIES)
			{
				throw new kCoreException("Max number of categories was reached", kCoreException::MAX_NUMBER_OF_CATEGORIES_REACHED);
			}
			
			$chunkedCategoryLoadThreshold = kConf::get('kmc_chunked_category_load_threshold');
			if ($numOfCatsForPartner >= $chunkedCategoryLoadThreshold)
				PermissionPeer::enableForPartner(PermissionName::DYNAMIC_FLAG_KMC_CHUNKED_CATEGORY_LOAD, PermissionType::SPECIAL_FEATURE);
		}
		
		$this->applyInheritance();
		
		// set the depth of the parent category + 1
		if ($this->isNew() || $this->isColumnModified(categoryPeer::PARENT_ID))
		{
			$parentCat = $this->getParentCategory();
			if ($this->getParentId() !== 0){
				$this->setDepth($parentCat->getDepth() + 1);
			}else{
				$this->setDepth(0);
			}
				$this->setChildsDepth();
		}
		
		if ($this->getDepth() >= self::MAX_CATEGORY_DEPTH)
		{
			throw new kCoreException("Max depth was reached", kCoreException::MAX_CATEGORY_DEPTH_REACHED);
		} 
		
		if ($this->isColumnModified(categoryPeer::NAME) || $this->isColumnModified(categoryPeer::PARENT_ID))
		{
			$this->updateFullName();
			$this->renameOnEntries();
		}
		else if ($this->isColumnModified(categoryPeer::FULL_NAME))
		{
			$this->renameOnEntries();
		}

		
		// happens in 3 cases:
		// 1. name of the current category was updated
		// 2. full name of the parent category was updated and it was set here as child
		// 3. parent id was changed
		if ($this->isColumnModified(categoryPeer::FULL_NAME)) 
			$this->setChildsFullNames();
		

		// save the childs 
		foreach($this->childs_for_save as $child)
		{
			$child->save();
		}
		$this->childs_for_save = array();
		
		if ($this->isColumnModified(categoryPeer::DELETED_AT) && $this->getDeletedAt() !== null)
		{
			$this->moveEntriesToParent();
		}
		
		$updateEntriesCount = false;
		if ($this->isColumnModified(categoryPeer::PARENT_ID) && !$this->isNew())
		{
			$updateEntriesCount = true;
			$oldParentId = $this->old_parent_id;
			$newParentId = $this->parent_id;
			$this->old_parent_id = null;
		}
				
		parent::save($con);
		
		if ($updateEntriesCount)
		{
			$parentsCategories = array();
			// decrease for the old parent category
			if($oldParentId)
				$oldParentCat = categoryPeer::retrieveByPK($oldParentId);
			if ($oldParentCat)
			{
				$parentsCategories[] = $oldParentCat->getId();
				$parentsCategories = array_merge($parentsCategories, $oldParentCat->getAllParentsIds());
			}						
			// increase for the new parent category
			$newParentCat = categoryPeer::retrieveByPK($newParentId);
			if ($newParentCat)
			{			
				$parentsCategories[] = $newParentCat->getId();
				$parentsCategories = array_merge($parentsCategories, $newParentCat->getAllParentsIds());
			}

			$parentsCategories = array_unique($parentsCategories);
				
			foreach($parentsCategories as $parentsCategoryId)
			{
				$this->updateCategoryCount($parentsCategoryId);
			}
		}			
	}
	
	private function updateCategoryCount($categoryId)
	{
		$category = categoryPeer::retrieveByPK($categoryId);
		if(!$category)
			return;
		
		$allChildren = $category->getAllChildren();
		$allSubCategoriesIds = array();
		$allSubCategoriesIds[] = $category->getId();
		
		if (count($allChildren))
		{
			foreach ($allChildren as $child)
				$allSubCategoriesIds[] = $child->getId();	
		}
		
		$c = KalturaCriteria::create(entryPeer::OM_CLASS);
		$entryFilter = new entryFilter();
		$entryFilter->set("_matchor_categories_ids", implode(',',$allSubCategoriesIds));
		$entryFilter->attachToCriteria($c);
		$c->setLimit(0);
		$entries = entryPeer::doSelect($c);

		$category->setEntriesCount($c->getRecordsCount());
		$category->save();
	}

	/* (non-PHPdoc)
	 * @see lib/model/om/Basecategory#postUpdate()
	 */
	public function postUpdate(PropelPDO $con = null)
	{
		if ($this->alreadyInSave)
			return parent::postUpdate($con);
		
		$objectDeleted = false;
		if($this->isColumnModified(categoryPeer::DELETED_AT) && !is_null($this->getDeletedAt()))
			$objectDeleted = true;
			
		$ret = parent::postUpdate($con);
		
		if($objectDeleted)
			kEventsManager::raiseEvent(new kObjectDeletedEvent($this));
			
		return $ret;
	}
	
	public function setName($v)
	{
		$this->old_full_name = $this->getFullName();
		
		$v = categoryPeer::getParsedName($v);
		parent::setName($v);
	}
	
	public function setFullName($v)
	{
		$this->old_full_name = $this->getFullName();				
		parent::setFullName($v);
	}
	
	public function setParentId($v)
	{
		$this->old_full_name = $this->getFullName();
		
		$this->validateParentIdIsNotChild($v);
		
		if ($v !== 0)
		{
			$parentCat = $this->getPeer()->retrieveByPK($v);
			if (!$parentCat)
				throw new Exception("Parent category [".$this->getParentId()."] was not found on category [".$this->getId()."]");
		}
			
		$this->old_parent_id = $this->parent_id;
		parent::setParentId($v);
		$this->parent_category = null;
	}
	
	/**
	 * Set the child categories full names using the current full path
	 */
	public function setChildsFullNames()
	{
		if ($this->isNew()) // do nothing
			return;
			
		$this->loadChildsForSave();
		foreach($this->childs_for_save as $child)
		{
			$child->setFullName($this->getFullName() . categoryPeer::CATEGORY_SEPARATOR . $child->getName());
		}
	}
	
	/**
	 * Set the child depth
	 */
	public function setChildsDepth()
	{
		if ($this->isNew()) // do nothing
			return;
			
		$this->loadChildsForSave();
		foreach($this->childs_for_save as $child)
		{
			$child->setDepth($this->getDepth() + 1);
			$child->setChildsDepth();
		}
	}
   	
   	/**
   	* entryAlreadyBlongToCategory return true when entry was already belong to this category before
   	*/
	private function entryAlreadyBlongToCategory($entryCategoriesIds)
	{
		if (!$entryCategoriesIds){
				return false;
		}
		
		$categoriesIds = implode(",",$entryCategoriesIds);
		foreach($entryCategoriesIds as $entryCategoryId)
		{
			if ($entryCategoryId == $this->id)
				return true;
		}
		
		return false;
	}
    
    
      /**
       * Increment entries count (will increment recursively the parent categories too)
       */
      public function incrementEntriesCount($increase = 1, $entryCategoriesAddedIds = null)
      {
            if($entryCategoriesAddedIds && $this->entryAlreadyBlongToCategory($entryCategoriesAddedIds))
                  return;
                  
            $this->setEntriesCount($this->getEntriesCount() + $increase);
            if ($this->getParentId())
            {
                  $parentCat = $this->getParentCategory();
                  if ($parentCat)
                  {
                        $parentCat->incrementEntriesCount($increase, $entryCategoriesAddedIds);
                  }
            }
            
            $this->save();
      }
      
      /**
       * Decrement entries count (will decrement recursively the parent categories too)
       */
      public function decrementEntriesCount($decrease = 1, $entryCategoriesRemovedIds = null)
      {
            
            
            if($this->entryAlreadyBlongToCategory($entryCategoriesRemovedIds))
                  return;
            
            if($this->getDeletedAt(null))
                  return;
                  
            $newCount = $this->getEntriesCount() - $decrease;
            
            if ($newCount < 0)
            	$newCount = 0;
			$this->setEntriesCount($newCount);
            
            if ($this->getParentId())
            {
                  $parentCat = $this->getParentCategory();
                  if ($parentCat)
                  {
                        $parentCat->decrementEntriesCount($decrease, $entryCategoriesRemovedIds);
                  }
            }
            
            $this->save();
      }
      
	public function validateFullNameIsUnique()
	{
		$name = $this->getFullName();
		$category = categoryPeer::getByFullNameExactMatch($name);
		if ($category)
			throw new kCoreException("Duplicate category: $name", kCoreException::DUPLICATE_CATEGORY);
	}
	
	public function setDeletedAt($v)
	{
		$this->loadChildsForSave();
		foreach($this->childs_for_save as $child)
		{
			$child->setDeletedAt($v);
		}
		
		$this->setStatus(CategoryStatus::DELETED);
		parent::setDeletedAt($v);
		$this->save();
	}
	
	public function delete(PropelPDO $con = null)
	{
		$this->loadChildsForSave();
		foreach($this->childs_for_save as $child)
		{
			$child->delete($con);
		}
		
		$this->moveEntriesToParent(); // will remove from entries
		parent::delete($con);
	}
	
	private function loadChildsForSave()
	{
		if (count($this->childs_for_save) > 0)
			return;
			
		$this->childs_for_save = $this->getChilds();
	}
	
	/**
	 * Update the current full path by using the parent full path (if exists)
	 * 
	 * @param category $parentCat
	 */
	private function updateFullName()
	{
		$parentCat = $this->getParentCategory();
			
		if ($parentCat)
		{
			$this->setFullName($parentCat->getFullName() . categoryPeer::CATEGORY_SEPARATOR . $this->getName());
		}
		else
		{
			$this->setFullName($this->getName());
		}
		
		$this->validateFullNameIsUnique();
	}
	
	/**
	 * Rename category name on linked entries
	 */
	private function renameOnEntries()
	{
		if ($this->isNew()) // do nothing
			return;
		/*
		 * TODO: this can be queued to a batch job as this will only affect the
		 * categories returned by baseEntry.get and not the search functionality 
		 * (because search translates categories to ids and use ids to search)   
		*/ 
		$c = KalturaCriteria::create(entryPeer::OM_CLASS);
		$entryFilter = new entryFilter();
		$entryFilter->set("_matchor_categories_ids", $this->getId());
		$entryFilter->attachToCriteria($c);
		$entries = entryPeer::doSelect($c);
		KalturaLog::log("category::save - Updating [".count($entries)."] entries");
		foreach($entries as $entry)
		{
			$entry->renameCategory($this->old_full_name, $this->getFullName());
			$entry->justSave();
		}
	}
	
	/**
	 * Moves the entries from the current category to the parent category (if exists) or remove from entry (if parent doesn't exists)
	 */
	private function moveEntriesToParent()
	{
		$parentCat = $this->getParentCategory();
		if ($parentCat)
		{
			$c = KalturaCriteria::create(entryPeer::OM_CLASS);
			$entryFilter = new entryFilter();
			$entryFilter->set("_matchor_categories_ids", $this->getId());
			$entryFilter->attachToCriteria($c);
			$entries = entryPeer::doSelect($c);
			foreach($entries as $entry)
			{
				$entry->renameCategory($this->getFullName(), $parentCat->getFullName());
				$entry->syncCategories();
			}
		}
		else
		{
			$this->removeFromEntries();
		}
	}
	
	/**
	 * Removes the category from the entries
	 */
	private function removeFromEntries()
	{
		$c = KalturaCriteria::create(entryPeer::OM_CLASS);
		$entryFilter = new entryFilter();
		$entryFilter->set("_matchor_categories_ids", $this->getId());
		$entryFilter->attachToCriteria($c);
		$entries = entryPeer::doSelect($c);
		foreach($entries as $entry)
		{
			$entry->removeCategory($this->full_name);
			$entry->syncCategories();
		}
	}
	
	/**
	 * Validate recursivly that the new parent id is not one of the child categories
	 * 
	 * @param int $parentId
	 */
	public function validateParentIdIsNotChild($parentId)
	{
		$childs = $this->getChilds();
		foreach($childs as $child)
		{
			if ($child->getId() == $parentId)
			{
				throw new kCoreException("Parent id [$parentId] is one of the childs", kCoreException::PARENT_ID_IS_CHILD);
			}
			
			$child->validateParentIdIsNotChild($parentId);
		}
	}
	
	/**
	 * @return catagory
	 */
	public function getParentCategory()
	{
		if ($this->parent_category === null && $this->getParentId())
			$this->parent_category = $this->getPeer()->retrieveByPK($this->getParentId());
			
		return $this->parent_category;
	}
	
	/**
	* return array of all parents ids
	* @return array
	*/
	public function getAllParentsIds()
	{
		$parentsIds = array();
		if ($this->getParentId()){
			$parentsIds[] = $this->getParentId();
			$parentsIds = array_merge($parentsIds, $this->getParentCategory()->getAllParentsIds());
		}

		return $parentsIds; 
	}
	
	
	/**
	 * @return array
	 */
	public function getChilds()
	{
		if ($this->isNew())
			return array();
			
		$c = new Criteria();
		$c->add(categoryPeer::PARENT_ID, $this->getId());
		return categoryPeer::doSelect($c);
	}
	
	/**
	 * @return array
	 */
	public function getAllChildren()
	{
		$c = new Criteria();
		$c->add(categoryPeer::FULL_NAME, $this->getFullName() . '%', Criteria::LIKE);
		$c->addAnd(categoryPeer::PARTNER_ID,$this->getPartnerId(),Criteria::EQUAL);
		return categoryPeer::doSelect($c);
	}
	
	/**
	 * Initialize new category using patnerId and fullName, this will also create the needed categories for the fullName
	 * 
	 * @param $partnerId
	 * @param $fullName
	 * @return category
	 */
	public static function createByPartnerAndFullName($partnerId, $fullName)
	{
		$fullNameArray = explode(categoryPeer::CATEGORY_SEPARATOR, $fullName);
		$fullNameTemp = "";
		$parentId = 0;
		foreach($fullNameArray as $name)
		{
			if ($fullNameTemp === "")
				$fullNameTemp .= $name;
			else
				$fullNameTemp .= (categoryPeer::CATEGORY_SEPARATOR . $name);
				
			$category = categoryPeer::getByFullNameExactMatch($fullNameTemp);
			if (!$category)
			{
				$category = new category();
				$category->setPartnerId($partnerId);
				$category->setParentId($parentId);
				$category->setName($name);
				$category->save();
			}
			$parentId = $category->getId();
		}
		return $category;
	}

	public function getCacheInvalidationKeys()
	{
		return array("category:partnerId=".$this->getPartnerId());
	}
	
	/**
	 * Applies default values to this object.
	 * This method should be called from the object's constructor (or
	 * equivalent initialization method).
	 * @see        __construct()
	 */
	public function applyDefaultValues()
	{
		$this->setName('');
		$this->setFullName('');
		$this->setEntriesCount(0);
		$this->setDirectEntriesCount(0);
		$this->setMembersCount(0);
		$this->setPendingMembersCount(0);
		$this->setDisplayInSearch(displayInSearchType::LISTED);
		$this->setPrivacy(PrivacyType::ALL);
		$this->setMembershipSetting(CategoryMembershipSettingType::MANUAL);
		$this->setUserJoinPolicy(UserJoinPolicyType::NOT_ALLOWED);
		$this->setDefaultPermissionLevel(CategoryKuserPermissionLevel::MODERATOR);
		$this->setContributionPolicy(ContributionPolicyType::MODERATOR);
		$this->setStatus(CategoryStatus::ACTIVE);
		$this->setPrivacyContext(false);
	}
	
	
	/**
	 * Get the [id] column value.
	 * 
	 * @return     int
	 */
	public function getIntId()
	{
		return $this->getId();
	}
	
	//TODO - remove this function when changing sphinx_log model from entryId to objectId and objectType
	public function getEntryId()
	{
		return null;
	}
	
	/* (non-PHPdoc)
	 * @see IIndexable::getObjectIndexName()
	 */
	public function getObjectIndexName()
	{
		return categoryPeer::getOMClass(false);
	}
	
	public function getSearchText()
	{
		return 'category->getSearchText';
	}
	
	public function getMembers()
	{
		return 'active members';
	}
	
/* (non-PHPdoc)
	 * @see IIndexable::getIndexFieldsMap()
	 */
	public function getIndexFieldsMap()
	{
		return array(
		/*sphinx => propel */
			'id' => 'id',
			'partner_id' => 'partnerId',
			'name' => 'name',
			'full_name' => 'fullName',
			'description' => 'description',
			'tags' => 'tags',
			'status' => 'status',
			'kuser_id' => 'kuserId',
			'display_in_search' => 'displayInSearch',	
			'search_text' => 'searchText',
			'members' => 'members'
		);
	}
	
	/**
	 * @return string field type, string, int or timestamp
	 */
	public function getIndexFieldType($field)
	{
		if(isset(self::$indexFieldTypes[$field]))
			return self::$indexFieldTypes[$field];
			
		return null;
	}
	
	

	
		/* (non-PHPdoc)
	 * @see lib/model/om/Baseentry#postInsert()
	 */
	public function postInsert(PropelPDO $con = null)
	{
		parent::postInsert($con);
	
		if (!$this->alreadyInSave)
			kEventsManager::raiseEvent(new kObjectAddedEvent($this));
	}
	
	
	public function applyInheritance()
	{
		if ($this->getMembershipSetting() == CategoryMembershipSettingType::INHERT)
		{
			$parentCategory = $this->getParentCategory();
			$this->setUserJoinPolicy($parentCategory->getUserJoinPolicy());
			$this->setDefaultPermissionLevel($parentCategory->getDefaultPermissionLevel());
			$this->setKuserId($parentCategory->getKuserId());
			$this->setContributionPolicy($parentCategory->getContributionPolicy());
		}
	}
}
