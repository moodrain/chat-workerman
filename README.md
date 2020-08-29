### chat

基于 workerman 依赖 redis, mysql 服务的简单用户对用户聊天系统

#### 数据存放

| 数据| 存放 |
|:----    |:---|
| 在线用户 id 集合 | redis set
| 用户连接 | php 数组 如 [ uid => conn ]
| 在线消息 | redis hash 如 msg:u:{uid} {msgId} {msgData}  
| 离线消息 | mysql 表结构见备注

#### 判断用户在线

* 在客户端连接聊天服务器时加个心跳，超过 一定秒数 未发消息则踢下线（关闭连接，并在 redis 在线用户集合中删除）
* 根据 redis 的数据，业务接口层提供用户是否在线查询

#### 聊天流程

1. 客户端打开 app，先从 业务服务器 查询 mysql 中的未读消息
2. 客户端连接 聊天服务器，使用 token 认证。将 用户 加入 在线用户集合，并 发送 redis 中的未读消息
3. 使用 心跳机制 管理用户的在线状态
4. 客户端发送消息时，需先判断对方是否在线，在线则请求 聊天服务器 接口，下线则请求 业务服务器 接口
5. 聊天服务器收到信息时，生成唯一 msgId。判断对方是否在线，不在线直接存 mysql，在线则先存入 redis，然后根据 uid 查找在 php 数组中对方的连接并转发
6. 客户端收到信息时，需要向聊天服务器发送带有 msgId 的回执。聊天服务器收到回执后删除 redis 中对应的在线消息
7. 定时将 redis 中 超过 30 秒未收到回执的消息重发，超过 3 次则将该消息从 redis 移入 mysql
8. 因为网络问题一条消息可能会推多次，客户端需要根据 msgId 去重（如果接受过这个 msgId 了，则不告知用户，但还是要发送回执）

#### 备注

##### 表结构

user 表

| 字段| 类型 |
|:----    |:---|
| id | int primary auto_increment
| token | char

chat_message_unread 表

| 字段| 类型 |
|:----    |:---|
| id | int primary auto_increment
| message_id | int
| from_user_id | int
| to_user_id | int
| data | json
| created_at | datetime
