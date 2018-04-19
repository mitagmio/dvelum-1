<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Orm\Distributed;

use Dvelum\Orm\Distributed;
use Dvelum\Orm;
use Dvelum\Db;
use Dvelum\Orm\Model;
use \Exception;
use Psr\Log\LogLevel;

class Store extends \Dvelum\Orm\Record\Store
{
    /**
     * @var Distributed $sharding
     */
    protected $sharding;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->sharding = Distributed::factory();
    }

    /**
     * Insert record
     * @param Orm\RecordInterface $object
     * @param array $data
     * @return mixed record id
     */
    protected function insertRecord(Orm\RecordInterface $object , array $data)
    {
        $insert = $this->sharding->reserveIndex($object);

        if(empty($insert)){
            $this->log->log(LogLevel::ERROR,$object->getName() . '::insert Cannot reserve index for object');
            return false;
        }

        $insertId = $insert->getId();
        $shardingField =  $this->sharding->getShardField();

        $object->set($shardingField, $insert->getShard());

        $data[$shardingField] = $insert->getShard();
        $data[$object->getConfig()->getPrimaryKey()] = $insertId;

        $data = $object->serializeLinks($data);
        $db = $this->getDbConnection($object);

        try {
            $db->insert($object->getTable(), $data);
        }catch (Exception $e) {
            $this->sharding->deleteIndex($object, $insertId);
            $this->log->log(LogLevel::ERROR,$object->getName() . '::insert ' . $e->getMessage());
            return false;
        }
        return $insertId;
    }
    /**
     * Delete record
     * @param Orm\RecordInterface $object
     * @return bool
     */
    protected function deleteRecord(Orm\RecordInterface $object ) : bool
    {
        $objectConfig = $object->getConfig();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $indexModel = Model::factory($indexObject);

        $db = $indexModel->getDbConnection();
        $db->beginTransaction();
        try{
            $o = Orm\Record::factory($indexObject, $object->getId());
            $o->delete(false);
        }catch (Exception $e){
            if ($this->log) {
                $this->log->log(LogLevel::ERROR, $object->getName() . ' cant delete index' . $object->getId());
            }
            return false;
        }

        if(!parent::deleteRecord($object)){
            $db->rollback();
            return false;
        }
        $db->commit();
        return  true;
    }
    /**
     * @param Orm\RecordInterface $object
     * @return Db\Adapter
     */
    protected function getDbConnection(Orm\RecordInterface $object) : Db\Adapter
    {
        $shardId = null;

        $field = $this->sharding->getShardField();
        $shardId = $object->get($field);

        if(empty($shardId)){
            $shardId = null;
        }

        $objectModel = Model::factory($object->getName());
        return $objectModel->getDbManager()->getDbConnection($objectModel->getDbConnectionName(), null, $shardId);
    }

    /**
     * Update record
     * @param Orm\RecordInterface $object
     * @return bool
     */
    protected function updateRecord(Orm\RecordInterface $object ) : bool
    {
        $db = $this->getDbConnection($object);

        $updates = $object->getUpdates();

        if($object->getConfig()->hasEncrypted())
            $updates = $this->encryptData($object , $updates);

        $this->updateLinks($object);

        $updates = $object->serializeLinks($updates);

        $shardingIndex = $object->getConfig()->getDistributedIndexObject();
        $indexModel = Model::factory($shardingIndex);
        $indexConfig = $indexModel->getObjectConfig();
        $indexDb = $indexModel->getDbConnection();

        $indexFields = $indexConfig->getFields();
        $indexUpdates = [];

        foreach ($updates as $field => $value){
            if(isset($indexFields[$field]) && $indexConfig->getPrimaryKey()!==$field){
                $indexUpdates[$field] = $value;
            }
        }

        if(!empty($updates)){
            try{
                if(!empty($indexUpdates)){
                    $indexDb->beginTransaction();
                    $indexDb->update($indexModel->table(),$indexUpdates, $db->quoteIdentifier($object->getConfig()->getPrimaryKey()).' = '.$object->getId());
                }
                $db->update($object->getTable() , $updates, $db->quoteIdentifier($object->getConfig()->getPrimaryKey()).' = '.$object->getId());
                if(!empty($indexUpdates)){
                    $indexDb->commit();
                }
            }catch (Exception $e){
                if($this->log){
                    $this->log->log(LogLevel::ERROR,$object->getName().'::update '.$e->getMessage());
                }
                if(!empty($indexUpdates)){
                    $indexDb->rollback();
                }
                return false;
            }
        }
        return true;
    }

    /**
     * Validate unique fields, object field groups
     * Returns array of errors or null .
     * @return  array | null
     */
    public function validateUniqueValues($objectName, $recordId, $groupsData) : ?array
    {

        $objectConfig = Orm\Record\Config::factory($objectName);
        $model = Model::factory($objectConfig->getDistributedIndexObject());

        $db = $model->getDbConnection();
        $primaryKey = $model->getPrimaryKey();

        try{
            foreach ($groupsData as $group)
            {
                $sql = $db->select()
                    ->from($model->table() , array('count'=>'COUNT(*)'));

                if($recordId)
                    $sql->where(' '.$db->quoteIdentifier($primaryKey).' != ?', $recordId);

                foreach ($group as $k=>$v)
                {
                    if($k===$primaryKey)
                        continue;

                    $sql->where($db->quoteIdentifier($k) . ' =?' , $v);
                }

                $count = $db->fetchOne($sql);

                if($count > 0){
                    return array_keys($group);
                }
            }
        }catch (Exception $e){

            if($this->log){
                $this->log->log(LogLevel::ERROR,$objectName .'::validate '.$e->getMessage());
            }
            return null;
        }

        return null;
    }
}