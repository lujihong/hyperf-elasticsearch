# ElasticSearch hyperf客户端

### Composer

```
composer require lujihong/elasticsearch
```

## 发布配置文件

```shell script
php ./bin/hyperf.php vendor:publish lujihong/elasticsearch
```

### Model

* index 相当于mysql中的表

```php
<?php
declare(strict_types=1);

namespace App\EsModel;

use Hyperf\Elasticsearch\Model\Model;
use Hyperf\Elasticsearch\Model\MySqlType;

/**
 * Author lujihong
 * Description
 */
class TestModel extends Model
{
    /**
     * @var string
     */
    protected string $index = 'test';

    /**
     * 字段类型映射ES，创建索引时使用
     * mysql的文本类型字段会自动映射转换为ES的text类型，并且字段设置：analyzer = ik_max_word，search_analyzer = ik_smart
     * mysql的日期相关类型字段会自动映射转换为ES的date类型，并且字段设置：format = yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||yyyy/MM/dd HH:mm:ss||yyyy/MM/dd||epoch_millis||epoch_second
     * @var array
     */
    protected array $casts = [
        'id' => MySqlType::bigint,
        'name' => MySqlType::varchar,
        'nickname' => MySqlType::varchar,
        'age' => MySqlType::int,
        'phone' => MySqlType::varchar,
        'remark' => [
            'type' => 'text',
            'analyzer' => 'standard'
        ],
        'created_at' => MySqlType::timestamp
    ];

}
```

### 查询

```php
<?php
declare(strict_types=1);

namespace App\Command;

use App\EsModel\TestModel;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;

#[Command]
class TestCommand extends HyperfCommand
{
    protected $name = 'test:command';

    public function handle()
    {
        //删除索引
        $deleteIndex = TestModel::query()->deleteIndex();
        echo '删除索引：' . $deleteIndex . PHP_EOL;

        //创建索引
        $createIndex = TestModel::query()->createIndex();
        echo '创建索引：' . $createIndex . PHP_EOL;

        //create方式创建文档
        $create = TestModel::query()->create([
            'id' => 1,
            'name' => '李星星',
            'nickname' => '星哥',
            'age' => 19,
            'phone' => '18524444444',
            'created_at' => time()
        ]);
        echo 'create:' . print_r($create->getAttributes(), true) . PHP_EOL;

        //按id更新文档
        $update = TestModel::query()->updateById([
            'name' => '李星星1'
        ], 1);
        echo 'updateById：' . print_r($update, true) . PHP_EOL;

        sleep(1);

        //按查询条件更新
        $updateByQuery = TestModel::query()
            ->whereTerm('name.raw', '李星星1')
            ->update(['name' => '李星星的7', 'nickname' => '小鬼呀2']);
        echo 'update：' . print_r($updateByQuery, true) . PHP_EOL;

        sleep(1);

        //insert方式批量插入，注意如果存在ID，数据又是一样的调用insert则会执行更新操作
        $insert = TestModel::query()->insert([[
            'id' => 2,
            'name' => '李四',
            'nickname' => '我是李四',
            'age' => 19,
            'phone' => '18524444444',
            'created_at' => time()
        ], [
            'id' => 3,
            'name' => '张三',
            'nickname' => '我是张三',
            'age' => 32,
            'phone' => '18415554444',
            'created_at' => time()
        ], [
            'id' => 4,
            'name' => '刘小小',
            'nickname' => 'liu',
            'age' => 18,
            'phone' => '18245447956',
            'created_at' => time()
        ], [
            'id' => 5,
            'name' => '张三山',
            'nickname' => '张san三',
            'age' => 21,
            'phone' => '13148774554',
            'created_at' => time()
        ], [
            'id' => 6,
            'name' => '李山使',
            'nickname' => '李山哈哈',
            'age' => 21,
            'phone' => '13854789887',
            'created_at' => time()
        ]]);
        echo 'insert:' . print_r($insert->toArray(), true) . PHP_EOL;

        //更新索引Mapping
        $updateMapping = TestModel::query()->updateIndexMapping([
            'testField' => [
                'type' => 'keyword',
                //...字段其他配置
            ],
            'field2' => 'keyword',
            //...
        ]);
        echo 'updateIndexMapping:' . print_r($updateMapping, true) . PHP_EOL;

        //更新索引Setting
        $updateSetting = TestModel::query()->updateIndexSetting([
            'number_of_replicas' => 0,
            'refresh_interval' => -1
            //...其他
        ]);
        echo 'updateIndexSetting:' . print_r($updateSetting, true) . PHP_EOL;

        //按id删除文档
        $deleteById = TestModel::query()->deleteById(4);
        echo 'deleteById:' . print_r($deleteById, true) . PHP_EOL;

        //按查询删除文档
        $delete = TestModel::query()
            ->whereTerm('age', 18)
            ->whereTerm('id', 4)
            ->delete();
        echo 'delete:' . print_r($delete, true) . PHP_EOL;

        //数据查询
        $result = TestModel::query()
            ->whereMatchPhrase('name', '李四')
            ->whereMatch('name', '李四')
            ->whereShouldMatchPhrase('name', '李四')
            ->whereShouldMatchPhrase('name', '张三')
            ->wherePrefix('name', '李')
            ->whereMultiMatch(['nickname', 'nickname.raw', 'nickname.english', 'nickname.standard', 'nickname.keyword', 'nickname.smart'], '张三')
            ->whereWildcard('phone.raw', '1314*554')
            ->whereMatchPhrase('name', '李星星')
            ->whereTerm('name.raw', '李星星') //注意whereTerm字段如果为字符串则需要在字段上加上.raw
            ->whereBetween('age', [0, 100]) //范围查询
            ->orderBy('phone.raw', 'desc') //排序
            ->selectHighlight(['name']) //设置高亮搜索关键词
//            ->get(size: 100)?->toArray(); //获取100条数据
//            ->increment('age', 50); //递增
//            ->first()?->toArray(); //获取一条
            ->page(1, 100)->toArray(); //分页查询
//            ->find(5)?->toArray(); //按id查找一条数据
        echo '数据查询:' . print_r($result, true) . PHP_EOL;
        
        //更多用法请查看源码
    }
}
```