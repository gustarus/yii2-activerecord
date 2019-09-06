<?php
/**
 * Created by PhpStorm.
 * User: supreme
 * Date: 04.05.14
 * Time: 12:27
 */

namespace webulla\activerecord;

use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord as BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\BadRequestHttpException;

/**
 * Class ActiveRecord
 * @package wbl\db\models
 *
 * Описание
 * Эта модель значительно расширяет функционал связей базовой модели @class ActiveRecord.
 * С помощью этой модели можно сохранять/удалять зависимые модели получаемые при помощи @method $this->hasMany() по методу in-one-touch.
 *
 * Пример
 *  // создаем экземпляр необходимой модели
 *    $model = new SomeRecord;
 *
 *    // загружаем модель из post запроса
 *    $post = Yii::$app->request->post();
 *    if($model->load($post)) {
 *        // загружаем измененный список моделей связи на основе старого списка
 *        $model->relatedRecords = RelatedRecord::loadMultipleDiff($model->relatedRecords, $post);
 *
 *        // сохраняем модель, а затем и зависимые модели
 *        $model->save();
 *    }
 *
 * @property array $relationsErrors
 */
class ActiveRecord extends BaseActiveRecord {

  /**
   * @var array[[class, link], ...]
   */
  private $_relations = [];

  /**
   * @var array
   */
  private $_relatedOld = [];


  /**
   * @inheritdoc
   */
  public function __set($name, $value) {
    if (isset($this->_relations[$name])) {
      $this->setRelation($name, $value);
    } else {
      parent::__set($name, $value);
    }
  }

  /**
   * Возвращает список имен связей с сылками на модели которые нужно держать актуальными.
   * Используется, например, когда нужно загрузить id связей из post запроса.
   *
   * Пример
   *  return [
   *      ['relationName' => RelationClass::className()],
   *      ...
   *  ];
   */
  public function getRelationsToKeepUpdated() {
    return [];
  }

  /**
   * @return bool
   * @throws \Exception
   * @throws \Throwable
   * @throws \yii\db\StaleObjectException
   */
  public function beforeDelete() {
    if (!parent::beforeDelete()) {
      return false;
    }

    $valid = true;
    foreach ($this->getRelationsToKeepUpdated() as $relationName => $relationClassName) {
      if (!$this->deleteRelation($relationName)) {
        $valid = false;
      }
    }

    return $valid;
  }

  /**
   * Регистрация связи.
   * @inheritdoc
   */
  public function hasMany($class, $link) {
    // получаем метод из которого была вызвана конструкция
    list(, $caller) = debug_backtrace(false);
    $method = $caller['function'];

    // регистрируем связь
    if (substr($method, 0, 3) == 'get') {
      $name = substr($method, 3);
      $name[0] = strtolower($name[0]);
      $this->registerRelation($class, $name, $link);
    }

    return parent::hasMany($class, $link);
  }

  /**
   * Регистрирует связь.
   * С этого момента она начинает отслеживаться и с ней можно выполнять операции сохранения/удаления зависимых моделей.
   * @param $class
   * @param $name
   * @param $link
   */
  public function registerRelation($class, $name, $link) {
    $this->_relations[$name] = [$class, $link];
  }

  /**
   * @inheritdoc
   */
  public function getRelation($name, $throwException = true) {
    return parent::getRelation($name, $throwException);
  }

  /**
   * Устанавливает все зависимые модели связи.
   * Присваивает значения связанных ключей.
   * @param $name
   * @param $records
   */
  public function setRelation($name, $records) {
    $this->_relatedOld[$name] = $this->$name;

    // trigger relation registration
    $this->getRelation($name);

    $attribute = array_keys($this->_relations[$name][1])[0];
    foreach ($records as $record) {
      $record->{$attribute} = $this->getPrimaryKey();
    }

    parent::populateRelation($name, $records);
  }

  /**
   * Выполняет валидацию всех зависимых моделей связи.
   * @param $name
   * @param null $attributeNames
   * @param bool $clearErrors
   * @return bool
   */
  public function validateRelation($name, $attributeNames = null, $clearErrors = true) {
    $valid = true;

    if ($models = $this->$name) {
      // получаем внешний ключ модели
      $key = key($this->_relations[$name][1]);

      // определяем список атрибутов без внешшнего ключа
      $attributeNames = array_flip($attributeNames ?: reset($models)->attributes());
      if (isset($attributeNames[$key])) {
        unset($attributeNames[$key]);
      }
      $attributeNames = array_flip($attributeNames);

      // валидируем кастомные атрибуты моделей
      /** @var ActiveRecord $record */
      foreach ($this->$name as $record) {
        !$record->validate($attributeNames, $clearErrors) && ($valid = false);
      }
    }

    return $valid;
  }

  /**
   * Возвращает все ошибки для моделей связи.
   * @param $name
   * @return array
   */
  public function getRelationErrors($name) {
    $errors = [];
    foreach ($this->$name as $model) {
      /** @var ActiveRecord $model */
      if ($model->errors) {
        $errors[] = $model->errors;
      }
    }

    return $errors;
  }

  /**
   * Сохзраняет изменения во всех зависимых моделях связи.
   * @param $name
   * @param bool $runValidation
   * @param null $attributeNames
   * @return bool
   * @throws \Exception
   * @throws \Throwable
   * @throws \yii\db\StaleObjectException
   */
  public function saveRelation($name, $runValidation = true, $attributeNames = null) {
    /** @var ActiveRecord $record */
    $success = true;

    // получаем списки моделей
    $recordsOld = $this->_relatedOld[$name];
    $recordsNew = $this->$name;

    // сохраняем модели
    $link = $this->_relations[$name][1];
    foreach ($recordsNew as $record) {
      $record->{key($link)} = $this->{reset($link)};
      !$record->save($runValidation, $attributeNames) && ($success = false);
    }

    // находим удаляемые модели
    $recordsOld = ArrayHelper::index($recordsOld, 'id');
    $recordsNew = ArrayHelper::index($recordsNew, 'id');
    $recordsDelete = array_diff_key($recordsOld, $recordsNew);

    // удаляем модели
    foreach ($recordsDelete as $record) {
      !$record->delete() && ($success = false);
    }

    return $success;
  }

  /**
   * Удаляет все зависимые модели связи.
   * @param $name
   * @return bool
   * @throws \Exception
   * @throws \Throwable
   * @throws \yii\db\StaleObjectException
   */
  public function deleteRelation($name) {
    $success = true;
    foreach ($this->$name as $model) {
      /** @var ActiveRecord $model */
      !$model->delete() && ($success = false);
    }

    return $success;
  }

  /**
   * Валидирует все связи.
   * @return bool
   */
  public function validateRelations() {
    $valid = true;
    foreach ($this->_relatedOld as $name => &$b) {
      !$this->validateRelation($name, null, true) && ($valid = false);
    }

    return $valid;
  }

  /**
   * Возвращает все ошибки для всех связей.
   * @return array
   */
  public function getRelationsErrors() {
    $errors = [];
    foreach ($this->_relatedOld as $name => &$b) {
      if ($_errors = $this->getRelationErrors($name)) {
        $errors[$name] = $_errors;
      }
    }

    return $errors;
  }

  /**
   * Сохраняет все связи.
   * @param bool $runValidation
   * @return bool
   * @throws \Exception
   * @throws \Throwable
   * @throws \yii\db\StaleObjectException
   */
  public function saveRelations($runValidation = true) {
    // валидация связей
    if ($runValidation && !$this->validateRelations()) {
      Yii::info('Relations not saved due to validation error.', __METHOD__);

      return false;
    }

    // сохранение связей
    $success = true;
    foreach ($this->_relatedOld as $name => &$b) {
      if (!$this->saveRelation($name, false, null)) {
        $success = false;
      }
    }

    return $success;
  }

  /**
   * Удаляет все связи.
   * @return bool
   * @throws \Exception
   * @throws \Throwable
   * @throws \yii\db\StaleObjectException
   */
  public function deleteRelations() {
    $success = true;
    foreach ($this->_relatedOld as $name => &$b) {
      !$this->deleteRelation($name) && ($success = false);
    }

    return $success;
  }

  /**
   * Клонирует модели без сохранения в базе.
   */
  public function cloneRelation($name) {
    $clones = [];
    foreach ($this->$name as $model) {
      $clone = new $model;
      $clone->attributes = $model->attributes;
      $clones[] = $clone;
    }

    return $clones;
  }

  /**
   * @param string $name
   * @param [] $condition
   */
  public function filterRelation($name, $condition) {
    $this->$name = array_filter($this->$name, function ($relation) use ($condition) {
      $attributes = $relation->attributes;
      foreach ($condition as $attribute => $value) {
        if (!isset($attributes[$attribute]) || $attributes[$attribute] !== $value) {
          return false;
        }
      }

      return true;
    });
  }

  /**
   * Метод можно переопределить чтобы отформатировать значения как необходимо перед установкой в модель.
   * Используется в методе $this->load.
   **/
  protected function prepare($data) {
    return $data;
  }

  /**
   * Метод повторяет метод load Model но, в случае с этим методом, вызывает метод парсинга атрибутов.
   * Это позволяет подготавливать значения перед сохранением.
   * @param array $data
   * @param null $formName
   * @return bool
   * @throws BadRequestHttpException
   * @throws InvalidConfigException
   */
  public function load($data, $formName = null) {
    $scope = $formName === null ? $this->formName() : $formName;

    $raw = null;
    if ($scope === '' && !empty($data)) {
      $raw = $data;
    } elseif (isset($data[$scope])) {
      $raw = $data[$scope];
    }

    if ($raw) {
      $formatted = $this->prepare($raw);
      if (empty($formatted)) {
        throw new BadRequestHttpException('Empty result was reterned from $this->prepare.');
      }

      $this->setAttributes($formatted);
      return true;
    }

    return false;
  }

  /**
   * @param $data
   * @param string[] $relationsNames
   * @return bool
   * @throws BadRequestHttpException
   * @throws \yii\base\InvalidConfigException
   */
  public function loadRelations($data, $relationsNames) {
    $useRelations = [];
    $defaultRelations = $this->getRelationsToKeepUpdated();
    foreach ($relationsNames as $relationName) {
      if (!isset($defaultRelations[$relationName])) {
        throw new InvalidConfigException('
                        Invalid relation name: ' . $relationName . '.
                        This relation doesn\'t exists in $this->getRelationsToKeepUpdated().
                    ');
      }

      $useRelations[$relationName] = $defaultRelations[$relationName];
    }

    foreach ($useRelations as $name => $className) {
      /** @var ActiveRecord $className */
      /** @var ActiveRecord[] $records */
      $records = $className::loadMultipleDiff($this->$name, $data);
      if ($records !== false) {
        $this->$name = $records;
      }
    }

    return true;
  }

  /**
   * @param $data
   * @return bool
   * @throws \yii\base\InvalidConfigException
   */
  public function loadRelationsPrimaries($data) {
    foreach ($this->getRelationsToKeepUpdated() as $name => $className) {
      /** @var ActiveRecord $instance */
      $instance = new $className;
      if (isset($data[$instance->formName()])) {
        $relations = $this->$name;
        foreach ($data[$instance->formName()] as $index => $row) {
          if (isset($row['id']) && $row['id']) {
            $relations[$index]->id = $row['id'];
          }
        }

        $this->$name = $relations;
      }
    }

    return true;
  }

  /**
   * Метод позволяет загрузить сразу несколько моделей, что позволяет использовать его в табличном вводе.
   * @param ActiveRecord[] $records
   * @param array $data
   * @param string $formName
   * @return ActiveRecord[]|false
   * @throws BadRequestHttpException
   * @throws \yii\base\InvalidConfigException
   */
  public static function loadMultipleDiff($records, $data, $formName = null) {
    /** @var ActiveRecord $record */
    $class = self::className();
    $records = $records ?: [];
    $record = reset($records) ?: new $class;

    // получаем список элементов
    $scope = $formName === null ? $record->formName() : $formName;
    if ($scope != '') {
      $data = $data && isset($data[$scope]) ? $data[$scope] : null;
    }

    // собираем коллекцию элементов
    $recordsOld = ArrayHelper::index($records, 'id');
    $recordsNew = [];
    if ($data && is_array($data)) {
      $class = get_class($record);
      foreach ($data as $item) {
        $record = isset($item['id']) && isset($recordsOld[$item['id']]) ? $recordsOld[$item['id']] : new $class;
        $record->load($item, '');
        $recordsNew[] = $record;
      }
    }

    return $recordsNew;
  }

  /**
   * @return ActiveRecord
   */
  public function deepClone() {
    $clone = new self;
    $clone->attributes = $this->attributes;

    foreach ($this->getRelationsToKeepUpdated() as $name => $className) {
      $clone->populateRelation($name, $this->cloneRelation($name));
    }

    return $clone;
  }
}
