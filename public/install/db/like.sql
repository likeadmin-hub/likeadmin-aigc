SET NAMES utf8mb4;
SET
    FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for la_admin
-- ----------------------------
DROP TABLE IF EXISTS `la_admin`;
CREATE TABLE `la_admin`
(
    `id`               int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT,
    `root`             tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否超级管理员 0-否 1-是',
    `name`             varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '名称',
    `avatar`           varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '用户头像',
    `account`          varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '账号',
    `password`         varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '密码',
    `login_time`       int(10)                                                       NULL     DEFAULT NULL COMMENT '最后登录时间',
    `login_ip`         varchar(39) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL     DEFAULT '' COMMENT '最后登录ip',
    `multipoint_login` tinyint(1) UNSIGNED                                           NULL     DEFAULT 1 COMMENT '是否支持多处登录：1-是；0-否；',
    `disable`          tinyint(1) UNSIGNED                                           NULL     DEFAULT 0 COMMENT '是否禁用：0-否；1-是；',
    `create_time`      int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time`      int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time`      int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '管理员表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_admin_dept
-- ----------------------------
DROP TABLE IF EXISTS `la_admin_dept`;
CREATE TABLE `la_admin_dept`
(
    `admin_id` int(10) NOT NULL DEFAULT 0 COMMENT '管理员id',
    `dept_id`  int(10) NOT NULL DEFAULT 0 COMMENT '部门id',
    PRIMARY KEY (`admin_id`, `dept_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_admin_jobs
-- ----------------------------
DROP TABLE IF EXISTS `la_admin_jobs`;
CREATE TABLE `la_admin_jobs`
(
    `admin_id` int(10) NOT NULL COMMENT '管理员id',
    `jobs_id`  int(10) NOT NULL COMMENT '岗位id',
    PRIMARY KEY (`admin_id`, `jobs_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_admin_role
-- ----------------------------
DROP TABLE IF EXISTS `la_admin_role`;
CREATE TABLE `la_admin_role`
(
    `admin_id` int(10) NOT NULL COMMENT '管理员id',
    `role_id`  int(10) NOT NULL COMMENT '角色id',
    PRIMARY KEY (`admin_id`, `role_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_admin_session
-- ----------------------------
DROP TABLE IF EXISTS `la_admin_session`;
CREATE TABLE `la_admin_session`
(
    `id`          int(11) UNSIGNED                                             NOT NULL AUTO_INCREMENT,
    `admin_id`    int(11) UNSIGNED                                             NOT NULL COMMENT '用户id',
    `terminal`    tinyint(1)                                                   NOT NULL DEFAULT 1 COMMENT '客户端类型：1-pc管理后台 2-mobile手机管理后台',
    `token`       varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '令牌',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `expire_time` int(10)                                                      NOT NULL COMMENT '到期时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `admin_id_client` (`admin_id`, `terminal`) USING BTREE COMMENT '一个用户在一个终端只有一个token',
    UNIQUE INDEX `token` (`token`) USING BTREE COMMENT 'token是唯一的'
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '管理员会话表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_article
-- ----------------------------
DROP TABLE IF EXISTS `la_article`;
CREATE TABLE `la_article`
(
    `id`            int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT '文章id',
    `tenant_id`     int(11)                                                       NOT NULL COMMENT '租户ID',
    `cid`           int(11)                                                       NOT NULL COMMENT '文章分类',
    `title`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文章标题',
    `desc`          varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '简介',
    `abstract`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '文章摘要',
    `image`         varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '文章图片',
    `author`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '作者',
    `content`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '文章内容',
    `click_virtual` int(10)                                                       NULL     DEFAULT 0 COMMENT '虚拟浏览量',
    `click_actual`  int(11)                                                       NULL     DEFAULT 0 COMMENT '实际浏览量',
    `is_show`       tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '是否显示:1-是.0-否',
    `sort`          int(5)                                                        NULL     DEFAULT 0 COMMENT '排序',
    `create_time`   int(11)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time`   int(11)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time`   int(11)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 4
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文章表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_article
-- ----------------------------
BEGIN;
INSERT INTO `la_article`
VALUES (1, 0, 3, '让生活更精致！五款居家好物推荐，实用性超高', '##好物推荐🔥',
        '随着当代生活节奏的忙碌，很多人在闲暇之余都想好好的享受生活。随着科技的发展，也出现了越来越多可以帮助我们提升幸福感，让生活变得更精致的产品，下面周周就给大家盘点五款居家必备的好物，都是实用性很高的产品，周周可以保证大家买了肯定会喜欢。',
        'resource/image/tenantapi/default/article01.png', '红花',
        '<p>拥有一台投影仪，闲暇时可以在家里直接看影院级别的大片，光是想想都觉得超级爽。市面上很多投影仪大几千，其实周周觉得没必要，选泰捷这款一千多的足够了，性价比非常高。</p><p>泰捷的专业度很高，在电视TV领域研发已经十年，有诸多专利和技术创新，荣获国内外多项技术奖项，拿下了腾讯创新工场投资，打造的泰捷视频TV端和泰捷电视盒子都获得了极高评价。</p><p>这款投影仪的分辨率在3000元内无敌，做到了真1080P高分辨率，也就是跟市场售价三千DLP投影仪一样的分辨率，真正做到了分毫毕现，像桌布的花纹、天空的云彩等，这些细节都清晰可见。</p><p>亮度方面，泰捷达到了850ANSI流明，同价位一般是200ANSI。这是因为泰捷为了提升亮度和LCD技术透射率低的问题，首创高功率LED灯源，让其亮度做到同价位最好。专业媒体也进行了多次对比，效果与3000元价位投影仪相当。</p><p>操作系统周周也很喜欢，完全不卡。泰捷作为资深音视频品牌，在系统优化方面有十年的研发经验，打造出的“零极”系统是业内公认效率最高、速度最快的系统，用户也评价它流畅度能一台顶三台，而且为了解决行业广告多这一痛点，系统内不植入任何广告。</p>',
        1, 2, 1, 0, 1663317759, 1727070911, NULL),
       (2, 0, 2, '埋葬UI设计师的坟墓不是内卷，而是免费模式', '',
        '本文从另外一个角度，聊聊作者对UI设计师职业发展前景的担忧，欢迎从事UI设计的同学来参与讨论，会有赠书哦',
        'resource/image/tenantapi/default/article02.jpeg', '小明',
        '<p><br></p><p style=\"text-align: justify;\">一个职业，卷，根本就没什么大不了的，尤其是成熟且收入高的职业，不卷才不符合事物发展的规律。何况 UI 设计师的人力市场到今天也和 5 年前一样，还是停留在大型菜鸡互啄的场面。远不能和医疗、证券、教师或者演艺练习生相提并论。</p><p style=\"text-align: justify;\">真正会让我对UI设计师发展前景觉得悲观的事情就只有一件 —— 国内的互联网产品免费机制。这也是一个我一直以来想讨论的话题，就在这次写一写。</p><p style=\"text-align: justify;\">国内互联网市场的发展，是一部浩瀚的 “免费经济” 发展史。虽然今天免费已经是深入国内民众骨髓的认知，但最早的中文互联网也是需要付费的，网游也都是要花钱的。</p><p style=\"text-align: justify;\">只是自有国情在此，付费确实阻碍了互联网行业的扩张和普及，一批创业家就开始通过免费的模式为用户提供服务，从而扩大了自己的产品覆盖面和普及程度。</p><p style=\"text-align: justify;\">印象最深的就是免费急先锋周鸿祎，和现在鲜少出现在公众视野不同，一零年前他是当之无愧的互联网教主，因为他开发出了符合中国国情的互联网产品 “打法”，让 360 的发展如日中天。</p><p style=\"text-align: justify;\">就是他在自传中提到：</p><p style=\"text-align: justify;\">只要是在互联网上每个人都需要的服务，我们就认为它是基础服务，基础服务一定是免费的，这样的话不会形成价值歧视。就是说，只要这种服务是每个人都一定要用的，我一定免费提供，而且是无条件免费。增值服务不是所有人都需要的，这个比例可能会相当低，它只是百分之几甚至更少比例的人需要，所以这种服务一定要收费……</p><p style=\"text-align: justify;\">这就是互联网的游戏规则，它决定了要想建立一个有效的商业模式，就一定要有海量的用户基数……</p>',
        2, 4, 1, 0, 1663322854, 1727071178, NULL),
       (3, 0, 1, '金山电池公布“沪广深市民绿色生活方式”调查结果', '',
        '60%以上受访者认为高质量的10分钟足以完成“自我充电”', 'resource/image/tenantapi/default/article03.png',
        '中网资讯科技',
        '<p style=\"text-align: left;\"><strong>深圳，2021年10月22日）</strong>生活在一线城市的沪广深市民一向以效率见称，工作繁忙和快节奏的生活容易缺乏充足的休息。近日，一项针对沪广深市民绿色生活方式而展开的网络问卷调查引起了大家的注意。问卷的问题设定集中于市民对休息时间的看法，以及从对循环充电电池的使用方面了解其对绿色生活方式的态度。该调查采用随机抽样的模式，并对最终收集的1,500份有效问卷进行专业分析后发现，超过60%的受访者表示，在每天的工作时段能拥有10分钟高质量的休息时间，就可以高效“自我充电”。该调查结果反映出，在快节奏时代下，人们需要高质量的休息时间，也要学会利用高效率的休息方式和工具来应对快节奏的生活，以时刻保持“满电”状态。</p><p style=\"text-align: left;\">　　<strong>60%以上受访者认为高质量的10分钟足以完成“自我充电”</strong></p><p style=\"text-align: left;\">　　这次调查超过1,500人，主要聚焦18至85岁的沪广深市民，了解他们对于休息时间的观念及使用充电电池的习惯，结果发现：</p><p style=\"text-align: left;\">　　· 90%以上有工作受访者每天工作时间在7小时以上，平均工作时间为8小时，其中43%以上的受访者工作时间超过9小时</p><p style=\"text-align: left;\">　　· 70%受访者认为在工作期间拥有10分钟“自我充电”时间不是一件困难的事情</p><p style=\"text-align: left;\">　　· 60%受访者认为在工作期间有10分钟休息时间足以为自己快速充电</p><p style=\"text-align: left;\">　　临床心理学家黄咏诗女士在发布会上分享为自己快速充电的实用技巧，她表示：“事实上，只要选择正确的休息方法，10分钟也足以为自己充电。以喝咖啡为例，我们可以使用心灵休息法 ── 静观呼吸，慢慢感受咖啡的温度和气味，如果能配合着聆听流水或海洋的声音，能够有效放松大脑及心灵。”</p><p style=\"text-align: left;\">　　这次调查结果反映出沪广深市民的希望在繁忙的工作中适时停下来，抽出10分钟喝杯咖啡、聆听音乐或小睡片刻，为自己充电。金山电池全新推出的“绿再十分充”超快速充电器仅需10分钟就能充好电，喝一杯咖啡的时间既能完成“自我充电”，也满足设备使用的用电需求，为提升工作效率和放松身心注入新能量。</p><p style=\"text-align: left;\">　　<strong>金山电池推出10分钟超快电池充电器*绿再十分充，以创新科技为市场带来革新体验</strong></p><p style=\"text-align: left;\">　　该问卷同时从沪广深市民对循环充电电池的使用方面进行了调查，以了解其对绿色生活方式的态度：</p><p style=\"text-align: left;\">　　· 87%受访者目前没有使用充电电池，其中61%表示会考虑使用充电电池</p><p style=\"text-align: left;\">　　· 58%受访者过往曾使用过充电电池，却只有20%左右市民仍在使用</p><p style=\"text-align: left;\">　　· 60%左右受访者认为充电电池尚未被广泛使用，主要障碍来自于充电时间过长、缺乏相关教育</p><p style=\"text-align: left;\">　　· 90%以上受访者认为充电电池充满电需要1小时或更长的时间</p><p style=\"text-align: left;\">　　金山电池一直致力于为大众提供安全可靠的充电电池，并与消费者的需求和生活方式一起演变及进步。今天，金山电池宣布推出10分钟超快电池充电器*绿再十分充，只需10分钟*即可将4粒绿再十分充充电电池充好电，充电速度比其他品牌提升3倍**。充电器的LED灯可以显示每粒电池的充电状态和模式，并提示用户是否错误插入已损坏电池或一次性电池。尽管其体型小巧，却具备多项创新科技 ，如拥有独特的充电算法以优化充电电流，并能根据各个电池类型、状况和温度用最短的时间为充电电池充好电;绿再十分充内置横流扇，有效防止电池温度过热和提供低噪音的充电环境等。<br></p>',
        11, 4, 1, 0, 1663322665, 1727071154, NULL);

COMMIT;

-- ----------------------------
-- Table structure for la_article_cate
-- ----------------------------
DROP TABLE IF EXISTS `la_article_cate`;
CREATE TABLE `la_article_cate`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT COMMENT '文章分类id',
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `name`        varchar(90) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '分类名称',
    `sort`        int(11)                                                      NULL DEFAULT 0 COMMENT '排序',
    `is_show`     tinyint(1)                                                   NULL DEFAULT 1 COMMENT '是否显示:1-是;0-否',
    `create_time` int(10)                                                      NULL DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                      NULL DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 3
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文章分类表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_article_cate
-- ----------------------------
BEGIN;
INSERT INTO `la_article_cate`
VALUES (1, 0, '科技', 0, 1, 1663317280, 1663317280, NULL),
       (2, 0, '生活', 0, 1, 1663317280, 1663321464, NULL),
       (3, 0, '好物', 0, 1, 1727070858, 1727070858, NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_article_collect
-- ----------------------------
DROP TABLE IF EXISTS `la_article_collect`;
CREATE TABLE `la_article_collect`
(
    `id`          int(10) UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`   int(11)             NOT NULL COMMENT '租户ID',
    `user_id`     int(10) UNSIGNED    NOT NULL DEFAULT 0 COMMENT '用户ID',
    `article_id`  int(10) UNSIGNED    NOT NULL DEFAULT 0 COMMENT '文章ID',
    `status`      tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '收藏状态 0-未收藏 1-已收藏',
    `create_time` int(10) UNSIGNED    NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time` int(10) UNSIGNED    NOT NULL DEFAULT 0 COMMENT '更新时间',
    `delete_time` int(10)             NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文章收藏表'
  ROW_FORMAT = Dynamic;
-- ----------------------------
-- Table structure for la_config
-- ----------------------------
DROP TABLE IF EXISTS `la_config`;
CREATE TABLE `la_config`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT,
    `type`        varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '类型',
    `name`        varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `value`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci        NULL COMMENT '值',
    `create_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_decorate_page
-- ----------------------------
DROP TABLE IF EXISTS `la_decorate_page`;
CREATE TABLE `la_decorate_page`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`   int(10)                                                       NOT NULL COMMENT '租户ID',
    `type`        tinyint(2) UNSIGNED                                           NOT NULL DEFAULT 10 COMMENT '页面类型 1=系统首页, 2=个人中心, 3=客服设置 4-PC首页',
    `name`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '页面名称',
    `data`        text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '页面数据',
    `meta`        text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '页面设置',
    `create_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                              NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 6
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '装修页面配置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_decorate_page
-- ----------------------------
BEGIN;
INSERT INTO `la_decorate_page`
VALUES (1, 0, 1, '系统首页',
        '[{\"title\":\"搜索\",\"name\":\"search\",\"disabled\":1,\"content\":{},\"styles\":{}},{\"title\":\"首页轮播图\",\"name\":\"banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/banner001.png\",\"name\":\"\",\"link\":{\"id\":6,\"name\":\"来自瓷器的爱\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":6},\"type\":\"article\"},\"is_show\":\"1\",\"bg\":\"/resource/image/tenantapi/default/banner001_bg.png\"},{\"image\":\"/resource/image/tenantapi/default/banner002.png\",\"name\":\"\",\"link\":{\"id\":3,\"name\":\"金山电池公布“沪广深市民绿色生活方式”调查结果\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":3},\"type\":\"article\"},\"is_show\":\"1\",\"bg\":\"/resource/image/tenantapi/default/banner002_bg.png\"},{\"is_show\":\"1\",\"image\":\"/resource/image/tenantapi/default/banner003.png\",\"name\":\"\",\"link\":{\"id\":1,\"name\":\"让生活更精致！五款居家好物推荐，实用性超高\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":1},\"type\":\"article\"},\"bg\":\"/resource/image/tenantapi/default/banner003_bg.png\"}],\"style\":1,\"bg_style\":1},\"styles\":{}},{\"title\":\"导航菜单\",\"name\":\"nav\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/nav01.png\",\"name\":\"资讯中心\",\"link\":{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\",\"canTab\":true},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav03.png\",\"name\":\"个人设置\",\"link\":{\"path\":\"/pages/user_set/user_set\",\"name\":\"个人设置\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav02.png\",\"name\":\"我的收藏\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav05.png\",\"name\":\"关于我们\",\"link\":{\"path\":\"/pages/as_us/as_us\",\"name\":\"关于我们\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav04.png\",\"name\":\"联系客服\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"}],\"style\":2,\"per_line\":5,\"show_line\":2},\"styles\":{}},{\"title\":\"首页中部轮播图\",\"name\":\"middle-banner\",\"content\":{\"enabled\":1,\"data\":[{\"is_show\":\"1\",\"image\":\"/resource/image/tenantapi/default/index_ad01.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/agreement/agreement\",\"name\":\"隐私政策\",\"query\":{\"type\":\"privacy\"},\"type\":\"shop\"}}]},\"styles\":{}},{\"id\":\"l84almsk2uhyf\",\"title\":\"资讯\",\"name\":\"news\",\"disabled\":1,\"content\":{},\"styles\":{}}]',
        '[{\"title\":\"页面设置\",\"name\":\"page-meta\",\"content\":{\"title\":\"首页\",\"bg_type\":\"0\",\"bg_color\":\"\",\"bg_image\":\"\",\"text_color\":\"2\",\"title_type\":\"1\",\"title_img\":\"\"},\"styles\":{}}]',
        1661757188, 1710989700);
INSERT INTO `la_decorate_page`
VALUES (2, 0, 2, '个人中心',
        '[{\"title\":\"用户信息\",\"name\":\"user-info\",\"disabled\":1,\"content\":{},\"styles\":{}},{\"title\":\"我的服务\",\"name\":\"my-service\",\"content\":{\"style\":1,\"title\":\"我的服务\",\"data\":[{\"image\":\"/resource/image/tenantapi/default/user_collect.png\",\"name\":\"我的收藏\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_setting.png\",\"name\":\"个人设置\",\"link\":{\"path\":\"/pages/user_set/user_set\",\"name\":\"个人设置\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_kefu.png\",\"name\":\"联系客服\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/wallet.png\",\"name\":\"我的点数\",\"link\":{\"path\":\"/packages/pages/user_wallet/user_wallet\",\"name\":\"我的点数\",\"type\":\"shop\"},\"is_show\":\"1\"}],\"enabled\":1},\"styles\":{}},{\"title\":\"个人中心广告图\",\"name\":\"user-banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/user_ad01.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_ad02.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"}]},\"styles\":{}}]',
        '[{\"title\":\"页面设置\",\"name\":\"page-meta\",\"content\":{\"title\":\"个人中心\",\"bg_type\":\"0\",\"bg_color\":\"\",\"bg_image\":\"\",\"text_color\":\"2\",\"title_type\":\"1\",\"title_img\":\"\"},\"styles\":{}}]',
        1661757188, 1710933097);
INSERT INTO `la_decorate_page`
VALUES (3, 0, 3, '客服设置',
        '[{\"title\":\"客服设置\",\"name\":\"customer-service\",\"content\":{\"title\":\"添加客服二维码\",\"time\":\"早上 9:30 - 19:00\",\"mobile\":\"1888888888\",\"qrcode\":\"/resource/image/common/kefu01.png\",\"remark\":\"长按添加客服或拨打客服热线\"},\"styles\":{}}]',
        '', 1661757188, 1710929953);
INSERT INTO `la_decorate_page`
VALUES (4, 0, 4, 'PC设置',
        '[{\"id\":\"lajcn8d0hzhed\",\"title\":\"首页轮播图\",\"name\":\"pc-banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/banner003.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\"}},{\"image\":\"/resource/image/tenantapi/default/banner002.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"}},{\"image\":\"/resource/image/tenantapi/default/banner001.png\",\"name\":\"\",\"link\":{}}]},\"styles\":{\"position\":\"absolute\",\"left\":\"40\",\"top\":\"75px\",\"width\":\"750px\",\"height\":\"340px\"}}},{\"id\":\"pc_tool_config_default\",\"title\":\"工具配置\",\"name\":\"pc-tool-config\",\"content\":{\"enabled\":1,\"data\":[]},\"styles\":{\"position\":\"absolute\",\"left\":\"820px\",\"top\":\"75px\",\"width\":\"300px\",\"height\":\"120px\"}}]',
        '', 1661757188, 1710990175);
INSERT INTO `la_decorate_page`
VALUES (5, 0, 5, '系统风格',
        '{\"themeColorId\":3,\"topTextColor\":\"white\",\"navigationBarColor\":\"#A74BFD\",\"themeColor1\":\"#A74BFD\",\"themeColor2\":\"#CB60FF\",\"buttonColor\":\"white\"}',
        '', 1710410915, 1710990415);
COMMIT;

-- ----------------------------
-- Table structure for la_decorate_tabbar
-- ----------------------------
DROP TABLE IF EXISTS `la_decorate_tabbar`;
CREATE TABLE `la_decorate_tabbar`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`   int(10)                                                       NOT NULL COMMENT '租户ID',
    `name`        varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '导航名称',
    `selected`    varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '未选图标',
    `unselected`  varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '已选图标',
    `link`        varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '链接地址',
    `is_show`     tinyint(255) UNSIGNED                                         NOT NULL DEFAULT 1 COMMENT '显示状态',
    `create_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 4
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '装修底部导航表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_decorate_tabbar
-- ----------------------------
BEGIN;
INSERT INTO `la_decorate_tabbar`
VALUES (1, 0, '首页', 'resource/image/tenantapi/default/tabbar_home_sel.png',
        'resource/image/tenantapi/default/tabbar_home.png',
        '{\"path\":\"/pages/index/index\",\"name\":\"系统首页\",\"type\":\"shop\"}', 1, 1662688157, 1662688157);
INSERT INTO `la_decorate_tabbar`
VALUES (2, 0, '资讯', 'resource/image/tenantapi/default/tabbar_text_sel.png',
        'resource/image/tenantapi/default/tabbar_text.png',
        '{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\",\"canTab\":\"1\"}', 1, 1662688157,
        1662688157);
INSERT INTO `la_decorate_tabbar`
VALUES (3, 0, '我的', 'resource/image/tenantapi/default/tabbar_me_sel.png',
        'resource/image/tenantapi/default/tabbar_me.png',
        '{\"path\":\"/pages/user/user\",\"name\":\"个人中心\",\"type\":\"shop\",\"canTab\":\"1\"}', 1, 1662688157,
        1662688157);
COMMIT;

-- ----------------------------
-- Table structure for la_dept
-- ----------------------------
DROP TABLE IF EXISTS `la_dept`;
CREATE TABLE `la_dept`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT COMMENT 'id',
    `name`        varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '部门名称',
    `pid`         bigint(20)                                                   NOT NULL DEFAULT 0 COMMENT '上级部门id',
    `sort`        int(11)                                                      NOT NULL DEFAULT 0 COMMENT '排序',
    `leader`      varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '负责人',
    `mobile`      varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '联系电话',
    `status`      tinyint(1)                                                   NOT NULL DEFAULT 0 COMMENT '部门状态（0停用 1正常）',
    `create_time` int(10)                                                      NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 2
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_dept
-- ----------------------------
BEGIN;
INSERT INTO `la_dept`
VALUES (1, '公司', 0, 0, 'boss', '12345698745', 1, 1650592684, 1653640368, NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_dev_crontab
-- ----------------------------
DROP TABLE IF EXISTS `la_dev_crontab`;
CREATE TABLE `la_dev_crontab`
(
    `id`          int(11)     NOT NULL AUTO_INCREMENT,
    `name`        varchar(32) NOT NULL COMMENT '定时任务名称',
    `type`        tinyint(1)  NOT NULL COMMENT '类型 1-定时任务',
    `system`      tinyint(4)           DEFAULT '0' COMMENT '是否系统任务 0-否 1-是',
    `remark`      varchar(255)         DEFAULT '' COMMENT '备注',
    `command`     varchar(64) NOT NULL COMMENT '命令内容',
    `params`      varchar(64)          DEFAULT '' COMMENT '参数',
    `status`      tinyint(1)  NOT NULL DEFAULT '0' COMMENT '状态 1-运行 2-停止 3-错误',
    `expression`  varchar(64) NOT NULL COMMENT '运行规则',
    `error`       varchar(256)         DEFAULT NULL COMMENT '运行失败原因',
    `last_time`   int(11)              DEFAULT NULL COMMENT '最后执行时间',
    `time`        varchar(64)          DEFAULT '0' COMMENT '实时执行时长',
    `max_time`    varchar(64)          DEFAULT '0' COMMENT '最大执行时长',
    `create_time` int(10)              DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)              DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)              DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='计划任务表';

-- ----------------------------
-- Table structure for la_dict_data
-- ----------------------------
DROP TABLE IF EXISTS `la_dict_data`;
CREATE TABLE `la_dict_data`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `name`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '数据名称',
    `value`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '数据值',
    `type_id`     int(11)                                                       NOT NULL COMMENT '字典类型id',
    `type_value`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '字典类型',
    `sort`        int(10)                                                       NULL     DEFAULT 0 COMMENT '排序值',
    `status`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '状态 0-停用 1-正常',
    `remark`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '备注',
    `create_time` int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 14
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '字典数据表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_dict_data
-- ----------------------------
BEGIN;
INSERT INTO `la_dict_data`
VALUES (1, '隐藏', '0', 1, 'show_status', 0, 1, '', 1656381543, 1656381543, NULL);
INSERT INTO `la_dict_data`
VALUES (2, '显示', '1', 1, 'show_status', 0, 1, '', 1656381550, 1656381550, NULL);
INSERT INTO `la_dict_data`
VALUES (3, '进行中', '0', 2, 'business_status', 0, 1, '', 1656381410, 1656381410, NULL);
INSERT INTO `la_dict_data`
VALUES (4, '成功', '1', 2, 'business_status', 0, 1, '', 1656381437, 1656381437, NULL);
INSERT INTO `la_dict_data`
VALUES (5, '失败', '2', 2, 'business_status', 0, 1, '', 1656381449, 1656381449, NULL);
INSERT INTO `la_dict_data`
VALUES (6, '待处理', '0', 3, 'event_status', 0, 1, '', 1656381212, 1656381212, NULL);
INSERT INTO `la_dict_data`
VALUES (7, '已处理', '1', 3, 'event_status', 0, 1, '', 1656381315, 1656381315, NULL);
INSERT INTO `la_dict_data`
VALUES (8, '拒绝处理', '2', 3, 'event_status', 0, 1, '', 1656381331, 1656381331, NULL);
INSERT INTO `la_dict_data`
VALUES (9, '禁用', '1', 4, 'system_disable', 0, 1, '', 1656312030, 1656312030, NULL);
INSERT INTO `la_dict_data`
VALUES (10, '正常', '0', 4, 'system_disable', 0, 1, '', 1656312040, 1656312040, NULL);
INSERT INTO `la_dict_data`
VALUES (11, '未知', '0', 5, 'sex', 0, 1, '', 1656062988, 1656062988, NULL);
INSERT INTO `la_dict_data`
VALUES (12, '男', '1', 5, 'sex', 0, 1, '', 1656062999, 1656062999, NULL);
INSERT INTO `la_dict_data`
VALUES (13, '女', '2', 5, 'sex', 0, 1, '', 1656063009, 1656063009, NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_dict_type
-- ----------------------------
DROP TABLE IF EXISTS `la_dict_type`;
CREATE TABLE `la_dict_type`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `name`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '字典名称',
    `type`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '字典类型名称',
    `status`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '状态 0-停用 1-正常',
    `remark`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '备注',
    `create_time` int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 6
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '字典类型表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_dict_type
-- ----------------------------
BEGIN;
INSERT INTO `la_dict_type`
VALUES (1, '显示状态', 'show_status', 1, '', 1656381520, 1656381520, NULL);
INSERT INTO `la_dict_type`
VALUES (2, '业务状态', 'business_status', 1, '', 1656381393, 1656381393, NULL);
INSERT INTO `la_dict_type`
VALUES (3, '事件状态', 'event_status', 1, '', 1656381075, 1656381075, NULL);
INSERT INTO `la_dict_type`
VALUES (4, '禁用状态', 'system_disable', 1, '', 1656311838, 1656311838, NULL);
INSERT INTO `la_dict_type`
VALUES (5, '用户性别', 'sex', 1, '', 1656062946, 1656380925, NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_file
-- ----------------------------
DROP TABLE IF EXISTS `la_file`;
CREATE TABLE `la_file`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `cid`         int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '类目ID',
    `source_id`   int(11) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '上传者id',
    `source`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '来源类型[0-后台,1-用户]',
    `type`        tinyint(2) UNSIGNED                                           NOT NULL DEFAULT 10 COMMENT '类型[10=图片, 20=视频]',
    `name`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '文件名称',
    `uri`         varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文件路径',
    `storage_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'platform' COMMENT '存储作用域',
    `storage_engine` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'local' COMMENT '存储引擎',
    `storage_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '存储域名',
    `create_time` int(10) UNSIGNED                                              NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文件表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_file_cate
-- ----------------------------
DROP TABLE IF EXISTS `la_file_cate`;
CREATE TABLE `la_file_cate`
(
    `id`          int(10) UNSIGNED                                             NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `pid`         int(10) UNSIGNED                                             NOT NULL DEFAULT 0 COMMENT '父级ID',
    `type`        tinyint(2) UNSIGNED                                          NOT NULL DEFAULT 10 COMMENT '类型[10=图片，20=视频，30=文件]',
    `name`        varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '分类名称',
    `create_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文件分类表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_generate_column
-- ----------------------------
DROP TABLE IF EXISTS `la_generate_column`;
CREATE TABLE `la_generate_column`
(
    `id`             int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `table_id`       int(11)                                                       NOT NULL DEFAULT 0 COMMENT '表id',
    `column_name`    varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '字段名称',
    `column_comment` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '字段描述',
    `column_type`    varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '字段类型',
    `is_required`    tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否必填 0-非必填 1-必填',
    `is_pk`          tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否为主键 0-不是 1-是',
    `is_insert`      tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否为插入字段 0-不是 1-是',
    `is_update`      tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否为更新字段 0-不是 1-是',
    `is_lists`       tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否为列表字段 0-不是 1-是',
    `is_query`       tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '是否为查询字段 0-不是 1-是',
    `query_type`     varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '=' COMMENT '查询类型',
    `view_type`      varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT 'input' COMMENT '显示类型',
    `dict_type`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '字典类型',
    `create_time`    int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time`    int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '代码生成表字段信息表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_generate_table
-- ----------------------------
DROP TABLE IF EXISTS `la_generate_table`;
CREATE TABLE `la_generate_table`
(
    `id`            int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `table_name`    varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '表名称',
    `table_comment` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '表描述',
    `template_type` tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '模板类型 0-单表(curd) 1-树表(curd)',
    `author`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '作者',
    `remark`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '备注',
    `generate_type` tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '生成方式  0-压缩包下载 1-生成到模块',
    `module_name`   varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '模块名',
    `class_dir`     varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '类目录名',
    `class_comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '类描述',
    `admin_id`      int(11)                                                       NULL     DEFAULT 0 COMMENT '管理员id',
    `menu`          text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '菜单配置',
    `delete`        text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '删除配置',
    `tree`          text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '树表配置',
    `relations`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '关联配置',
    `create_time`   int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '代码生成表信息表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_hot_search
-- ----------------------------
DROP TABLE IF EXISTS `la_hot_search`;
CREATE TABLE `la_hot_search`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`  int(11)                                                       NOT NULL COMMENT '租户ID',
    `name`        varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '关键词',
    `sort`        smallint(5) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '排序号',
    `create_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '热门搜索表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_jobs
-- ----------------------------
DROP TABLE IF EXISTS `la_jobs`;
CREATE TABLE `la_jobs`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `name`        varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '岗位名称',
    `code`        varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '岗位编码',
    `sort`        int(11)                                                       NULL     DEFAULT 0 COMMENT '显示顺序',
    `status`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '状态（0停用 1正常）',
    `remark`      varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '备注',
    `create_time` int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_notice_record
-- ----------------------------
DROP TABLE IF EXISTS `la_notice_record`;
CREATE TABLE `la_notice_record`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `user_id`     int(10) UNSIGNED                                              NOT NULL COMMENT '用户id',
    `title`       varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '标题',
    `content`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NOT NULL COMMENT '内容',
    `scene_id`    int(10) UNSIGNED                                              NULL     DEFAULT 0 COMMENT '场景',
    `read`        tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '已读状态;0-未读,1-已读',
    `recipient`   tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '通知接收对象类型;1-会员;2-商家;3-平台;4-游客(未注册用户)',
    `send_type`   tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '通知发送类型 1-系统通知 2-短信通知 3-微信模板 4-微信小程序',
    `notice_type` tinyint(1)                                                    NULL     DEFAULT NULL COMMENT '通知类型 1-业务通知 2-验证码',
    `extra`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '其他',
    `create_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '通知记录表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_notice_setting
-- ----------------------------
DROP TABLE IF EXISTS `la_notice_setting`;
CREATE TABLE `la_notice_setting`
(
    `id`            int(11)                                                       NOT NULL AUTO_INCREMENT,
    `scene_id`      int(10)                                                       NOT NULL COMMENT '场景id',
    `scene_name`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '场景名称',
    `scene_desc`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '场景描述',
    `recipient`     tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '接收者 1-用户 2-平台',
    `type`          tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '通知类型: 1-业务通知 2-验证码',
    `system_notice` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '系统通知设置',
    `sms_notice`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '短信通知设置',
    `oa_notice`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '公众号通知设置',
    `mnp_notice`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '小程序通知设置',
    `support`       char(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci     NOT NULL DEFAULT '' COMMENT '支持的发送类型 1-系统通知 2-短信通知 3-微信模板消息 4-小程序提醒',
    `update_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 5
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '通知设置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_notice_setting
-- ----------------------------
BEGIN;
INSERT INTO `la_notice_setting`
VALUES (1, 101, '登录验证码', '用户手机号码登录时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在登录，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
INSERT INTO `la_notice_setting`
VALUES (2, 102, '绑定手机验证码', '用户绑定手机号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\"}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在绑定手机号，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\"}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\"}',
        '2', NULL);
INSERT INTO `la_notice_setting`
VALUES (3, 103, '变更手机验证码', '用户变更手机号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在变更手机号，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
INSERT INTO `la_notice_setting`
VALUES (4, 104, '找回登录密码验证码', '用户找回登录密码号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在找回登录密码，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"0\",\"is_show\":\"1\",\"tips\":[\"可选变量 验证码:code\",\"示例：您正在找回登录密码，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"生效条件：1、管理后台完成短信设置。 2、第三方短信平台申请模板。\"]}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_official_account_reply
-- ----------------------------
DROP TABLE IF EXISTS `la_official_account_reply`;
CREATE TABLE `la_official_account_reply`
(
    `id`            int(11) UNSIGNED                                             NOT NULL AUTO_INCREMENT,
    `tenant_id`     int(11)                                                      NOT NULL COMMENT '租户ID',
    `name`          varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '规则名称',
    `keyword`       varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '关键词',
    `reply_type`    tinyint(1)                                                   NOT NULL COMMENT '回复类型 1-关注回复 2-关键字回复 3-默认回复',
    `matching_type` tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 1 COMMENT '匹配方式：1-全匹配；2-模糊匹配',
    `content_type`  tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 1 COMMENT '内容类型：1-文本',
    `content`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci        NOT NULL COMMENT '回复内容',
    `status`        tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '启动状态：1-启动；0-关闭',
    `sort`          int(11) UNSIGNED                                             NOT NULL DEFAULT 50 COMMENT '排序',
    `create_time`   int(10)                                                      NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time`   int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time`   int(10)                                                      NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '公众号消息回调表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_operation_log
-- ----------------------------
DROP TABLE IF EXISTS `la_operation_log`;
CREATE TABLE `la_operation_log`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT,
    `admin_id`    int(11)                                                       NOT NULL COMMENT '管理员ID',
    `admin_name`  varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '管理员名称',
    `account`     varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '管理员账号',
    `action`      varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL     DEFAULT '' COMMENT '操作名称',
    `type`        varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci   NOT NULL COMMENT '请求方式',
    `url`         varchar(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '访问链接',
    `params`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '请求数据',
    `result`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '请求结果',
    `ip`          varchar(39) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT 'ip地址',
    `create_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '系统日志表'
  ROW_FORMAT = Dynamic;



-- ----------------------------
-- Table structure for la_pay_config
-- ----------------------------
DROP TABLE IF EXISTS `la_pay_config`;
CREATE TABLE `la_pay_config`
(
    `id`      int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT,
    `name`    varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '模版名称',
    `pay_way` tinyint(1)                                                    NOT NULL COMMENT '支付方式:1-点数支付;2-微信支付;3-支付宝支付;',
    `config`  text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '对应支付配置(json字符串)',
    `icon`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '图标',
    `sort`    int(5)                                                        NULL     DEFAULT NULL COMMENT '排序',
    `remark`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '备注',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 4
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '支付配置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_pay_config
-- ----------------------------
BEGIN;
INSERT INTO `la_pay_config`
VALUES (1, '点数支付', 1, '', 'resource/image/common/balance_pay.png', 128, '点数支付备注');
INSERT INTO `la_pay_config`
VALUES (2, '微信支付', 2,
        '{\"interface_version\":\"v3\",\"merchant_type\":\"ordinary_merchant\",\"mch_id\":\"\",\"pay_sign_key\":\"\",\"apiclient_cert\":\"\",\"apiclient_key\":\"\"}',
        '/resource/image/common/wechat_pay.png', 123, '微信支付备注');
INSERT INTO `la_pay_config`
VALUES (3, '支付宝支付', 3,
        '{\"mode\":\"normal_mode\",\"merchant_type\":\"ordinary_merchant\",\"app_id\":\"\",\"private_key\":\"\",\"ali_public_key\":\"\"}',
        '/resource/image/common/ali_pay.png', 123, '支付宝支付');
COMMIT;

-- ----------------------------
-- Table structure for la_pay_way
-- ----------------------------
DROP TABLE IF EXISTS `la_pay_way`;
CREATE TABLE `la_pay_way`
(
    `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `pay_config_id` int(11)          NOT NULL COMMENT '支付配置ID',
    `scene`         tinyint(1)       NOT NULL COMMENT '场景:1-微信小程序;2-微信公众号;3-H5;4-PC;5-APP;',
    `is_default`    tinyint(1)       NOT NULL DEFAULT 0 COMMENT '是否默认支付:0-否;1-是;',
    `status`        tinyint(1)       NOT NULL DEFAULT 1 COMMENT '状态:0-关闭;1-开启;',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 8
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '支付方式表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_pay_way
-- ----------------------------
BEGIN;
INSERT INTO `la_pay_way`
VALUES (1, 1, 1, 0, 1);
INSERT INTO `la_pay_way`
VALUES (2, 2, 1, 1, 1);
INSERT INTO `la_pay_way`
VALUES (3, 1, 2, 0, 1);
INSERT INTO `la_pay_way`
VALUES (4, 2, 2, 1, 1);
INSERT INTO `la_pay_way`
VALUES (5, 1, 3, 0, 1);
INSERT INTO `la_pay_way`
VALUES (6, 2, 3, 1, 1);
INSERT INTO `la_pay_way`
VALUES (7, 3, 3, 0, 1);
COMMIT;

-- ----------------------------
-- Table structure for la_recharge_order
-- ----------------------------
DROP TABLE IF EXISTS `la_recharge_order`;
CREATE TABLE `la_recharge_order`
(
    `id`                    int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`             int(11)                                                       NOT NULL COMMENT '租户ID',
    `sn`                    varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '订单编号',
    `user_id`               int(11)                                                       NOT NULL COMMENT '用户id',
    `pay_sn`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '支付编号-冗余字段，针对微信同一主体不同客户端支付需用不同订单号预留。',
    `pay_way`               tinyint(2)                                                    NOT NULL DEFAULT 2 COMMENT '支付方式 2-微信支付 3-支付宝支付',
    `pay_status`            tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '支付状态：0-待支付；1-已支付',
    `pay_time`              int(10)                                                       NULL     DEFAULT NULL COMMENT '支付时间',
    `order_amount`          decimal(10, 2)                                                NOT NULL COMMENT '充值点数',
    `order_terminal`        tinyint(1)                                                    NULL     DEFAULT 1 COMMENT '终端',
    `transaction_id`        varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '第三方平台交易流水号',
    `refund_status`         tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '退款状态 0-未退款 1-已退款',
    `refund_transaction_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '退款交易流水号',
    `create_time`           int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time`           int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time`           int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '充值订单表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_refund_log
-- ----------------------------
DROP TABLE IF EXISTS `la_refund_log`;
CREATE TABLE `la_refund_log`
(
    `id`            int(11)                                                      NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`     int(11)                                                      NOT NULL COMMENT '租户ID',
    `sn`            varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '编号',
    `record_id`     int(11)                                                      NOT NULL COMMENT '退款记录id',
    `user_id`       int(11)                                                      NOT NULL DEFAULT 0 COMMENT '关联用户',
    `handle_id`     int(11)                                                      NOT NULL DEFAULT 0 COMMENT '处理人id（管理员id）',
    `order_amount`  decimal(10, 2) UNSIGNED                                      NOT NULL DEFAULT 0.00 COMMENT '订单总的应付款金额，冗余字段',
    `refund_amount` decimal(10, 2) UNSIGNED                                      NOT NULL DEFAULT 0.00 COMMENT '本次退款金额',
    `refund_status` tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '退款状态，0退款中，1退款成功，2退款失败',
    `refund_msg`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci        NULL COMMENT '退款信息',
    `create_time`   int(10) UNSIGNED                                             NULL     DEFAULT 0 COMMENT '创建时间',
    `update_time`   int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '退款日志'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_refund_record
-- ----------------------------
DROP TABLE IF EXISTS `la_refund_record`;
CREATE TABLE `la_refund_record`
(
    `id`             int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`      int(11)                                                       NOT NULL COMMENT '租户ID',
    `sn`             varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '退款编号',
    `user_id`        int(11)                                                       NOT NULL DEFAULT 0 COMMENT '关联用户',
    `order_id`       int(11)                                                       NOT NULL DEFAULT 0 COMMENT '来源订单id',
    `order_sn`       varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '来源单号',
    `order_type`     varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT 'order' COMMENT '订单来源 order-商品订单 recharge-充值订单',
    `order_amount`   decimal(10, 2) UNSIGNED                                       NOT NULL DEFAULT 0.00 COMMENT '订单总的应付款金额，冗余字段',
    `refund_amount`  decimal(10, 2) UNSIGNED                                       NOT NULL DEFAULT 0.00 COMMENT '本次退款金额',
    `transaction_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '第三方平台交易流水号',
    `refund_way`     tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '退款方式 1-线上退款 2-线下退款',
    `refund_type`    tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '退款类型 1-后台退款',
    `refund_status`  tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '退款状态，0退款中，1退款成功，2退款失败',
    `create_time`    int(10) UNSIGNED                                              NULL     DEFAULT 0 COMMENT '创建时间',
    `update_time`    int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '退款记录'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_sms_log
-- ----------------------------
DROP TABLE IF EXISTS `la_sms_log`;
CREATE TABLE `la_sms_log`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `scene_id`    int(11)                                                       NOT NULL COMMENT '场景id',
    `mobile`      varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '手机号码',
    `content`     varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '发送内容',
    `code`        varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL DEFAULT NULL COMMENT '发送关键字（注册、找回密码）',
    `is_verify`   tinyint(1)                                                    NULL DEFAULT 0 COMMENT '是否已验证；0-否；1-是',
    `check_num`   int(5)                                                        NULL DEFAULT 0 COMMENT '验证次数',
    `send_status` tinyint(1)                                                    NOT NULL COMMENT '发送状态：0-发送中；1-发送成功；2-发送失败',
    `send_time`   int(10)                                                       NOT NULL COMMENT '发送时间',
    `results`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '短信结果',
    `create_time` int(10)                                                       NULL DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                       NULL DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '短信记录表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_system_menu
-- ----------------------------
DROP TABLE IF EXISTS `la_system_menu`;
CREATE TABLE `la_system_menu`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `pid`         int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '上级菜单',
    `type`        char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci      NOT NULL DEFAULT '' COMMENT '权限类型: M=目录，C=菜单，A=按钮',
    `name`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '菜单名称',
    `icon`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '菜单图标',
    `sort`        smallint(5) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '菜单排序',
    `perms`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '权限标识',
    `paths`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '路由地址',
    `component`   varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '前端组件',
    `selected`    varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '选中路径',
    `params`      varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '路由参数',
    `is_cache`    tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否缓存: 0=否, 1=是',
    `is_show`     tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 1 COMMENT '是否显示: 0=否, 1=是',
    `is_disable`  tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否禁用: 0=否, 1=是',
    `app_code`    varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '应用标识',
    `source`      varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT 'core' COMMENT '菜单来源',
    `source_menu_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '来源菜单key',
    `is_core`     tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 1 COMMENT '是否核心菜单',
    `create_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 166
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '系统菜单表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_system_menu
-- ----------------------------
BEGIN;
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (4, 0, 'M', '权限管理', 'el-icon-Lock', 300, '', 'permission', '', '', '', 0, 1, 0, 1656664556, 1710472802);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (5, 0, 'C', '工作台', 'el-icon-Monitor', 1000, 'workbench/index', 'workbench', 'workbench/index', '', '', 0, 1,
        0, 1656664793, 1664354981);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (6, 4, 'C', '菜单', 'el-icon-Operation', 100, 'auth.menu/lists', 'menu', 'permission/menu/index', '', '', 1, 1,
        0, 1656664960, 1710472994);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (7, 4, 'C', '管理员', 'local-icon-shouyiren', 80, 'auth.admin/lists', 'admin', 'permission/admin/index', '', '',
        0, 1, 0, 1656901567, 1710473013);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (8, 4, 'C', '角色', 'el-icon-Female', 90, 'auth.role/lists', 'role', 'permission/role/index', '', '', 0, 1, 0,
        1656901660, 1710473000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (12, 8, 'A', '新增', '', 1, 'auth.role/add', '', '', '', '', 0, 1, 0, 1657001790, 1663750625);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (14, 8, 'A', '编辑', '', 1, 'auth.role/edit', '', '', '', '', 0, 1, 0, 1657001924, 1663750631);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (15, 8, 'A', '删除', '', 1, 'auth.role/delete', '', '', '', '', 0, 1, 0, 1657001982, 1663750637);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (16, 6, 'A', '新增', '', 1, 'auth.menu/add', '', '', '', '', 0, 1, 0, 1657072523, 1663750565);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (17, 6, 'A', '编辑', '', 1, 'auth.menu/edit', '', '', '', '', 0, 1, 0, 1657073955, 1663750570);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (18, 6, 'A', '删除', '', 1, 'auth.menu/delete', '', '', '', '', 0, 1, 0, 1657073987, 1663750578);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (19, 7, 'A', '新增', '', 1, 'auth.admin/add', '', '', '', '', 0, 1, 0, 1657074035, 1663750596);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (20, 7, 'A', '编辑', '', 1, 'auth.admin/edit', '', '', '', '', 0, 1, 0, 1657074071, 1663750603);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (21, 7, 'A', '删除', '', 1, 'auth.admin/delete', '', '', '', '', 0, 1, 0, 1657074108, 1663750609);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (23, 28, 'M', '开发工具', 'el-icon-EditPen', 40, '', 'dev_tools', '', '', '', 0, 1, 0, 1657097744, 1710473127);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (24, 23, 'C', '代码生成器', 'el-icon-DocumentAdd', 1, 'tools.generator/generateTable', 'code',
        'dev_tools/code/index', '', '', 0, 1, 0, 1657098110, 1658989423);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (25, 0, 'M', '组织管理', 'el-icon-OfficeBuilding', 400, '', 'organization', '', '', '', 0, 1, 0, 1657099914,
        1710472797);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (26, 25, 'C', '部门管理', 'el-icon-Coordinate', 100, 'dept.dept/lists', 'department',
        'organization/department/index', '', '', 1, 1, 0, 1657099989, 1710472962);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (27, 25, 'C', '岗位管理', 'el-icon-PriceTag', 90, 'dept.jobs/lists', 'post', 'organization/post/index', '', '',
        0, 1, 0, 1657100044, 1710472967);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (28, 0, 'M', '系统设置', 'el-icon-Setting', 200, '', 'setting', '', '', '', 0, 1, 0, 1657100164, 1710472807);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (29, 28, 'M', '网站设置', 'el-icon-Basketball', 100, '', 'website', '', '', '', 0, 1, 0, 1657100230, 1710473049);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (30, 29, 'C', '网站信息', '', 1, 'setting.web.web_setting/getWebsite', 'information',
        'setting/website/information', '', '', 0, 1, 0, 1657100306, 1657164412);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (31, 29, 'C', '网站备案', '', 1, 'setting.web.web_setting/getCopyright', 'filing', 'setting/website/filing', '',
        '', 0, 1, 1, 1657100434, 1657164723);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (32, 29, 'C', '政策协议', '', 1, 'setting.web.web_setting/getAgreement', 'protocol', 'setting/website/protocol',
        '', '', 0, 1, 1, 1657100571, 1657164770);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (33, 28, 'C', '存储设置', 'el-icon-FolderOpened', 70, 'setting.storage/lists', 'storage',
        'setting/storage/index', '', '', 0, 1, 0, 1657160959, 1710473095);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (34, 23, 'C', '字典管理', 'el-icon-Box', 1, 'setting.dict.dict_type/lists', 'dict', 'setting/dict/type/index',
        '', '', 0, 1, 0, 1657161211, 1663225935);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (35, 28, 'M', '系统维护', 'el-icon-SetUp', 50, '', 'system', '', '', '', 0, 1, 0, 1657161569, 1710473122);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (36, 35, 'C', '系统日志', '', 90, 'setting.system.log/lists', 'journal', 'setting/system/journal', '', '', 0, 1,
        0, 1657161696, 1710473253);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (37, 35, 'C', '系统缓存', '', 80, '', 'cache', 'setting/system/cache', '', '', 0, 1, 0, 1657161896, 1710473258);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (38, 35, 'C', '系统环境', '', 70, 'setting.system.system/info', 'environment', 'setting/system/environment', '',
        '', 0, 1, 0, 1657162000, 1710473265);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (39, 24, 'A', '导入数据表', '', 1, 'tools.generator/selectTable', '', '', '', '', 0, 1, 0, 1657162736,
        1657162736);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (40, 24, 'A', '代码生成', '', 1, 'tools.generator/generate', '', '', '', '', 0, 1, 0, 1657162806, 1657162806);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (41, 23, 'C', '编辑数据表', '', 1, 'tools.generator/edit', 'code/edit', 'dev_tools/code/edit', '/dev_tools/code',
        '', 1, 0, 0, 1657162866, 1663748668);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (42, 24, 'A', '同步表结构', '', 1, 'tools.generator/syncColumn', '', '', '', '', 0, 1, 0, 1657162934,
        1657162934);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (43, 24, 'A', '删除数据表', '', 1, 'tools.generator/delete', '', '', '', '', 0, 1, 0, 1657163015, 1657163015);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (44, 24, 'A', '预览代码', '', 1, 'tools.generator/preview', '', '', '', '', 0, 1, 0, 1657163263, 1657163263);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (51, 30, 'A', '保存', '', 1, 'setting.web.web_setting/setWebsite', '', '', '', '', 0, 1, 0, 1657164469,
        1663750649);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (52, 31, 'A', '保存', '', 1, 'setting.web.web_setting/setCopyright', '', '', '', '', 0, 1, 0, 1657164692,
        1663750657);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (53, 32, 'A', '保存', '', 1, 'setting.web.web_setting/setAgreement', '', '', '', '', 0, 1, 0, 1657164824,
        1663750665);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (54, 33, 'A', '设置', '', 1, 'setting.storage/setup', '', '', '', '', 0, 1, 0, 1657165303, 1663750673);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (55, 34, 'A', '新增', '', 1, 'setting.dict.dict_type/add', '', '', '', '', 0, 1, 0, 1657166966, 1663750783);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (56, 34, 'A', '编辑', '', 1, 'setting.dict.dict_type/edit', '', '', '', '', 0, 1, 0, 1657166997, 1663750789);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (57, 34, 'A', '删除', '', 1, 'setting.dict.dict_type/delete', '', '', '', '', 0, 1, 0, 1657167038, 1663750796);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (58, 62, 'A', '新增', '', 1, 'setting.dict.dict_data/add', '', '', '', '', 0, 1, 0, 1657167317, 1663750758);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (59, 62, 'A', '编辑', '', 1, 'setting.dict.dict_data/edit', '', '', '', '', 0, 1, 0, 1657167371, 1663750751);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (60, 62, 'A', '删除', '', 1, 'setting.dict.dict_data/delete', '', '', '', '', 0, 1, 0, 1657167397, 1663750768);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (61, 37, 'A', '清除系统缓存', '', 1, 'setting.system.cache/clear', '', '', '', '', 0, 1, 0, 1657173837,
        1657173939);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (62, 23, 'C', '字典数据管理', '', 1, 'setting.dict.dict_data/lists', 'dict/data', 'setting/dict/data/index',
        '/dev_tools/dict', '', 1, 0, 0, 1657174351, 1663745617);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (63, 158, 'M', '素材管理', 'el-icon-Picture', 0, '', 'material', '', '', '', 0, 1, 0, 1657507133, 1710472243);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (64, 63, 'C', '素材中心', 'el-icon-PictureRounded', 0, '', 'index', 'material/index', '', '', 0, 1, 0,
        1657507296, 1664355653);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (68, 6, 'A', '详情', '', 0, 'auth.menu/detail', '', '', '', '', 0, 1, 0, 1663725564, 1663750584);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (69, 7, 'A', '详情', '', 0, 'auth.admin/detail', '', '', '', '', 0, 1, 0, 1663725623, 1663750615);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (101, 158, 'M', '消息管理', 'el-icon-ChatDotRound', 80, '', 'message', '', '', '', 0, 1, 0, 1663838602,
        1710471874);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (102, 101, 'C', '通知设置', '', 0, 'notice.notice/settingLists', 'notice', 'message/notice/index', '', '', 0, 1,
        0, 1663839195, 1663839195);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (103, 102, 'A', '详情', '', 0, 'notice.notice/detail', '', '', '', '', 0, 1, 0, 1663839537, 1663839537);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (104, 101, 'C', '通知设置编辑', '', 0, 'notice.notice/set', 'notice/edit', 'message/notice/edit',
        '/message/notice', '', 0, 0, 0, 1663839873, 1663898477);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (107, 101, 'C', '短信设置', '', 0, 'notice.sms_config/getConfig', 'short_letter', 'message/short_letter/index',
        '', '', 0, 1, 0, 1663898591, 1664355708);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (108, 107, 'A', '设置', '', 0, 'notice.sms_config/setConfig', '', '', '', '', 0, 1, 0, 1663898644, 1663898644);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (109, 107, 'A', '详情', '', 0, 'notice.sms_config/detail', '', '', '', '', 0, 1, 0, 1663898661, 1663898661);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (112, 28, 'M', '用户设置', 'local-icon-keziyuyue', 90, '', 'user', '', '', '', 0, 1, 1, 1663903302, 1710473056);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (113, 112, 'C', '用户设置', '', 0, 'setting.user.user/getConfig', 'setup', 'setting/user/setup', '', '', 0, 1, 1,
        1663903506, 1663903506);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (114, 113, 'A', '保存', '', 0, 'setting.user.user/setConfig', '', '', '', '', 0, 1, 0, 1663903522, 1663903522);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (115, 112, 'C', '登录注册', '', 0, 'setting.user.user/getRegisterConfig', 'login_register',
        'setting/user/login_register', '', '', 0, 1, 0, 1663903832, 1663903832);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (116, 115, 'A', '保存', '', 0, 'setting.user.user/setRegisterConfig', '', '', '', '', 0, 1, 0, 1663903852,
        1663903852);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (117, 0, 'M', '租户管理', 'local-icon-user_biaoqian', 900, '', 'tenant', '', '', '', 0, 1, 0, 1663904351,
        1724998415);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (118, 117, 'C', '租户列表', 'local-icon-user_guanli', 100, 'user.user/lists', 'lists', 'tenant/lists/index',
        '', '', 0, 1, 0, 1663904392, 1724998428);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9006, 117, 'C', '任务记录', 'el-icon-List', 90, 'ai_task/lists', 'task', 'tenant/task/index',
        '', '', 0, 1, 0, '', 'core', 'core_ai_task_platform', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9007, 9006, 'A', '详情', '', 1, 'ai_task/detail', '', '',
        '', '', 0, 1, 0, '', 'core', 'core_ai_task_platform_detail', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (143, 35, 'C', '定时任务', '', 100, 'crontab.crontab/lists', 'scheduled_task',
        'setting/system/scheduled_task/index', '', '', 0, 1, 0, 1669357509, 1710473246);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (144, 35, 'C', '定时任务添加/编辑', '', 0, 'crontab.crontab/add:edit', 'scheduled_task/edit',
        'setting/system/scheduled_task/edit', '/setting/system/scheduled_task', '', 0, 0, 0, 1669357670, 1669357765);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (145, 143, 'A', '添加', '', 0, 'crontab.crontab/add', '', '', '', '', 0, 1, 0, 1669358282, 1669358282);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (146, 143, 'A', '编辑', '', 0, 'crontab.crontab/edit', '', '', '', '', 0, 1, 0, 1669358303, 1669358303);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (147, 143, 'A', '删除', '', 0, 'crontab.crontab/delete', '', '', '', '', 0, 1, 0, 1669358334, 1669358334);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (158, 0, 'M', '应用管理', 'el-icon-Postcard', 800, '', 'app', '', '', '', 0, 1, 0, 1677143430, 1710472079);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (161, 28, 'M', '支付设置', 'local-icon-set_pay', 80, '', 'pay', '', '', '', 0, 1, 1, 1677148075, 1710473061);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (162, 161, 'C', '支付方式', '', 0, 'setting.pay.pay_way/getPayWay', 'method', 'setting/pay/method/index', '', '',
        0, 1, 0, 1677148207, 1677148207);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (163, 161, 'C', '支付配置', '', 0, 'setting.pay.pay_config/lists', 'config', 'setting/pay/config/index', '', '',
        0, 1, 0, 1677148260, 1677148374);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (164, 162, 'A', '设置支付方式', '', 0, 'setting.pay.pay_way/setPayWay', '', '', '', '', 0, 1, 0, 1677219624,
        1677219624);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (165, 163, 'A', '配置', '', 0, 'setting.pay.pay_config/setConfig', '', '', '', '', 0, 1, 0, 1677219655,
        1677219655);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (166, 118, 'A', '新增租户', '', 0, 'tenant.tenant/add', '', '', '', '', 1, 1, 0, 1726822307, 1726822435);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (167, 118, 'A', '编辑租户', '', 0, 'tenant.tenant/edit', '', '', '', '', 1, 1, 0, 1726822372, 1726822440);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (168, 118, 'A', '租户详情', '', 0, 'tenant.tenant/detail', '', '', '', '', 1, 1, 0, 1726822396, 1726822444);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (169, 118, 'A', '删除租户', '', 0, 'tenant.tenant/delete', '', '', '', '', 1, 1, 0, 1726822416, 1726822449);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9004, 118, 'A', '租户点数充值', '', 110, 'tenant.tenant/rechargePoints', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_point_recharge', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9005, 118, 'A', '租户点数流水', '', 111, 'tenant.tenant/pointLogs', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_point_logs', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9012, 118, 'A', '进入租户后台', '', 112, 'tenant.tenant/sso', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_sso', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9000, 0, 'M', '应用管理', 'el-icon-Grid', 60, '', 'apps', '', '', '', 0, 1, 0, '', 'core', 'core_app_center', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9001, 9000, 'C', '应用中心', 'el-icon-Menu', 100, 'app/lists', 'center', 'apps/center/index', '', '', 0, 1, 0, '', 'core', 'core_app_center_index', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9020, 0, 'M', '系统服务', 'el-icon-Refresh', 50, '', 'system-service', '', '', '', 0, 1, 0, '', 'core', 'core_update_service', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9024, 9020, 'C', '接口渠道', 'el-icon-Connection', 110, 'upgrade/source', 'channel', 'update/channel/index', '', '', 0, 1, 0, '', 'core', 'core_update_channel', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9021, 9020, 'C', '版本更新', 'el-icon-UploadFilled', 100, 'upgrade/overview', 'version', 'update/version/index', '', '', 0, 1, 0, '', 'core', 'core_update_version', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9022, 9020, 'C', '授权信息', 'el-icon-Key', 90, 'upgrade/licenseInfo', 'license', 'update/license/index', '', '', 0, 1, 0, '', 'core', 'core_update_license', 1, 1727700000, 1727700000);
INSERT INTO `la_system_menu` (`id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9023, 9020, 'C', '版本日志', 'el-icon-List', 80, 'upgrade/logs', 'log', 'update/log/index', '', '', 0, 1, 0, '', 'core', 'core_update_log', 1, 1727700000, 1727700000);
COMMIT;

-- ----------------------------
-- Table structure for la_system_role
-- ----------------------------
DROP TABLE IF EXISTS `la_system_role`;
CREATE TABLE `la_system_role`
(
    `id`          int(11) UNSIGNED                                             NOT NULL AUTO_INCREMENT,
    `name`        varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `desc`        varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci      NOT NULL DEFAULT '' COMMENT '描述',
    `sort`        int(11)                                                      NULL     DEFAULT 0 COMMENT '排序',
    `create_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_system_role_menu
-- ----------------------------
DROP TABLE IF EXISTS `la_system_role_menu`;
CREATE TABLE `la_system_role_menu`
(
    `role_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
    `menu_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '菜单ID',
    PRIMARY KEY (`role_id`, `menu_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色菜单关系表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant`;
CREATE TABLE `la_tenant`
(
    `id`                  int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `sn`                  varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '编号',
    `name`                varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '名称',
    `avatar`              varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '租户头像',
    `tel`                 varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL     DEFAULT NULL COMMENT '联系方式',
    `disable`             tinyint(1) UNSIGNED                                           NULL     DEFAULT 0 COMMENT '是否禁用：0-否；1-是；',
    `tactics`             tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '分表策略: [0=否, 1=是]',
    `allow_custom_storage` tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '允许租户自定义存储',
    `allow_local_storage`  tinyint(1) UNSIGNED                                          NOT NULL DEFAULT 1 COMMENT '允许租户使用本地存储',
    `point_balance`       decimal(10, 2)                                                NOT NULL DEFAULT 0.00 COMMENT '租户点数余额',
    `notes`               varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '租户备注',
    `domain_alias`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '域名别名',
    `domain_alias_enable` tinyint(10)                                                   NOT NULL DEFAULT 1 COMMENT '启用域名别名：0-启用；1-禁用',
    `access_mode`         varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT 'subdomain' COMMENT '访问方式:subdomain自动子域名,id租户ID,alias别名',
    `create_time`         int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time`         int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time`         int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '租户表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_admin
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_admin`;
CREATE TABLE `la_tenant_admin`
(
    `id`               int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT,
    `tenant_id`        int(10)                                                       NOT NULL COMMENT '租户ID',
    `root`             tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否超级管理员 0-否 1-是',
    `name`             varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '名称',
    `avatar`           varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '用户头像',
    `account`          varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '账号',
    `password`         varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '密码',
    `login_time`       int(10)                                                       NULL     DEFAULT NULL COMMENT '最后登录时间',
    `login_ip`         varchar(39) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NULL     DEFAULT '' COMMENT '最后登录ip',
    `multipoint_login` tinyint(1) UNSIGNED                                           NULL     DEFAULT 1 COMMENT '是否支持多处登录：1-是；0-否；',
    `disable`          tinyint(1) UNSIGNED                                           NULL     DEFAULT 0 COMMENT '是否禁用：0-否；1-是；',
    `create_time`      int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time`      int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time`      int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '租户管理员表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_admin_dept
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_admin_dept`;
CREATE TABLE `la_tenant_admin_dept`
(
    `admin_id` int(10) NOT NULL DEFAULT 0 COMMENT '管理员id',
    `dept_id`  int(10) NOT NULL DEFAULT 0 COMMENT '部门id',
    PRIMARY KEY (`admin_id`, `dept_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '部门关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_admin_jobs
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_admin_jobs`;
CREATE TABLE `la_tenant_admin_jobs`
(
    `admin_id` int(10) NOT NULL COMMENT '管理员id',
    `jobs_id`  int(10) NOT NULL COMMENT '岗位id',
    PRIMARY KEY (`admin_id`, `jobs_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_admin_role
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_admin_role`;
CREATE TABLE `la_tenant_admin_role`
(
    `admin_id` int(10) NOT NULL COMMENT '管理员id',
    `role_id`  int(10) NOT NULL COMMENT '角色id',
    PRIMARY KEY (`admin_id`, `role_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色关联表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_admin_session
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_admin_session`;
CREATE TABLE `la_tenant_admin_session`
(
    `id`          int(11) UNSIGNED                                             NOT NULL AUTO_INCREMENT,
    `admin_id`    int(11) UNSIGNED                                             NOT NULL COMMENT '租户id',
    `terminal`    tinyint(1)                                                   NOT NULL DEFAULT 1 COMMENT '客户端类型：1-pc管理后台 2-mobile手机管理后台',
    `token`       varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '令牌',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `expire_time` int(10)                                                      NOT NULL COMMENT '到期时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `admin_id_client` (`admin_id`, `terminal`) USING BTREE COMMENT '一个用户在一个终端只有一个token',
    UNIQUE INDEX `token` (`token`) USING BTREE COMMENT 'token是唯一的'
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '管理员会话表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_config
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_config`;
CREATE TABLE `la_tenant_config`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT,
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `type`        varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '类型',
    `name`        varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `value`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci        NULL COMMENT '值',
    `create_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '配置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_dept
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_dept`;
CREATE TABLE `la_tenant_dept`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `name`        varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '部门名称',
    `pid`         bigint(20)                                                   NOT NULL DEFAULT 0 COMMENT '上级部门id',
    `sort`        int(11)                                                      NOT NULL DEFAULT 0 COMMENT '排序',
    `leader`      varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '负责人',
    `mobile`      varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '联系电话',
    `status`      tinyint(1)                                                   NOT NULL DEFAULT 0 COMMENT '部门状态（0停用 1正常）',
    `create_time` int(10)                                                      NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 2
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '租户部门表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_tenant_dept
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_dept`
VALUES (1, 0, '公司', 0, 0, 'boss', '12345698745', 1, 1650592684, 1653640368, NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_tenant_file
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_file`;
CREATE TABLE `la_tenant_file`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `cid`         int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '类目ID',
    `source_id`   int(11) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '上传者id',
    `source`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '来源类型[0-后台,1-用户]',
    `type`        tinyint(2) UNSIGNED                                           NOT NULL DEFAULT 10 COMMENT '类型[10=图片, 20=视频]',
    `name`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '文件名称',
    `uri`         varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文件路径',
    `storage_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'platform' COMMENT '存储作用域',
    `storage_engine` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'local' COMMENT '存储引擎',
    `storage_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '存储域名',
    `create_time` int(10) UNSIGNED                                              NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文件表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_file_cate
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_file_cate`;
CREATE TABLE `la_tenant_file_cate`
(
    `id`          int(10) UNSIGNED                                             NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `pid`         int(10) UNSIGNED                                             NOT NULL DEFAULT 0 COMMENT '父级ID',
    `type`        tinyint(2) UNSIGNED                                          NOT NULL DEFAULT 10 COMMENT '类型[10=图片，20=视频，30=文件]',
    `name`        varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '分类名称',
    `create_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10) UNSIGNED                                             NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '文件分类表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_jobs
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_jobs`;
CREATE TABLE `la_tenant_jobs`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `name`        varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '岗位名称',
    `code`        varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL COMMENT '岗位编码',
    `sort`        int(11)                                                       NULL     DEFAULT 0 COMMENT '显示顺序',
    `status`      tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '状态（0停用 1正常）',
    `remark`      varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '备注',
    `create_time` int(10)                                                       NOT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '修改时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '岗位表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_notice_record
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_notice_record`;
CREATE TABLE `la_tenant_notice_record`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `user_id`     int(10) UNSIGNED                                              NOT NULL COMMENT '用户id',
    `title`       varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '标题',
    `content`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NOT NULL COMMENT '内容',
    `scene_id`    int(10) UNSIGNED                                              NULL     DEFAULT 0 COMMENT '场景',
    `read`        tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '已读状态;0-未读,1-已读',
    `recipient`   tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '通知接收对象类型;1-会员;2-商家;3-平台;4-游客(未注册用户)',
    `send_type`   tinyint(1)                                                    NULL     DEFAULT 0 COMMENT '通知发送类型 1-系统通知 2-短信通知 3-微信模板 4-微信小程序',
    `notice_type` tinyint(1)                                                    NULL     DEFAULT NULL COMMENT '通知类型 1-业务通知 2-验证码',
    `extra`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '其他',
    `create_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '通知记录表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_notice_setting
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_notice_setting`;
CREATE TABLE `la_tenant_notice_setting`
(
    `id`            int(11)                                                       NOT NULL AUTO_INCREMENT,
    `tenant_id`     int(11)                                                       NOT NULL COMMENT '租户ID',
    `scene_id`      int(10)                                                       NOT NULL COMMENT '场景id',
    `scene_name`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '场景名称',
    `scene_desc`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '场景描述',
    `recipient`     tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '接收者 1-用户 2-平台',
    `type`          tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '通知类型: 1-业务通知 2-验证码',
    `system_notice` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '系统通知设置',
    `sms_notice`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '短信通知设置',
    `oa_notice`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '公众号通知设置',
    `mnp_notice`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '小程序通知设置',
    `support`       char(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci     NOT NULL DEFAULT '' COMMENT '支持的发送类型 1-系统通知 2-短信通知 3-微信模板消息 4-小程序提醒',
    `update_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 6
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '通知设置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_tenant_notice_setting
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_notice_setting`
VALUES (1, 0, 101, '登录验证码', '用户手机号码登录时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在登录，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
INSERT INTO `la_tenant_notice_setting`
VALUES (2, 0, 102, '绑定手机验证码', '用户绑定手机号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\"}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在绑定手机号，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\"}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\"}',
        '2', NULL);
INSERT INTO `la_tenant_notice_setting`
VALUES (3, 0, 103, '变更手机验证码', '用户变更手机号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在变更手机号，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"1\",\"is_show\":\"1\"}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
INSERT INTO `la_tenant_notice_setting`
VALUES (4, 0, 104, '找回登录密码验证码', '用户找回登录密码号码时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_123456\",\"content\":\"您正在找回登录密码，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"status\":\"0\",\"is_show\":\"1\",\"tips\":[\"可选变量 验证码:code\",\"示例：您正在找回登录密码，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"生效条件：1、管理后台完成短信设置。 2、第三方短信平台申请模板。\"]}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
INSERT INTO `la_tenant_notice_setting`
VALUES (5, 0, 105, '注册验证码', '用户注册账号时发送', 1, 2,
        '{\"type\":\"system\",\"title\":\"\",\"content\":\"\",\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\"]}',
        '{\"type\":\"sms\",\"template_id\":\"SMS_175615071\",\"content\":\"验证码${code}，您正在注册成为新用户，感谢您的支持！\",\"status\":\"1\",\"is_show\":\"1\",\"tips\":[\"可选变量 验证码:code\",\"示例：您正在申请注册，验证码${code}，切勿将验证码泄露于他人，本条验证码有效期5分钟。\",\"生效条件：1、管理后台完成短信设置。 2、第三方短信平台申请模板。\"]}',
        '{\"type\":\"oa\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"first\":\"\",\"remark\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '{\"type\":\"mnp\",\"template_id\":\"\",\"template_sn\":\"\",\"name\":\"\",\"tpl\":[],\"status\":\"0\",\"is_show\":\"\",\"tips\":[\"可选变量 验证码:code\",\"配置路径：小程序后台 > 功能 > 订阅消息\"]}',
        '2', NULL);
COMMIT;

-- ----------------------------
-- Table structure for la_tenant_pay_config
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_pay_config`;
CREATE TABLE `la_tenant_pay_config`
(
    `id`        int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT,
    `tenant_id` int(11)                                                       NOT NULL COMMENT '租户ID',
    `name`      varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '模版名称',
    `pay_way`   tinyint(1)                                                    NOT NULL COMMENT '支付方式:1-点数支付;2-微信支付;3-支付宝支付;',
    `config`    text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '对应支付配置(json字符串)',
    `icon`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '图标',
    `sort`      int(5)                                                        NULL     DEFAULT NULL COMMENT '排序',
    `remark`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '备注',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 4
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '支付配置表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_tenant_pay_config
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_pay_config`
VALUES (1, 0, '点数支付', 1, '', 'resource/image/common/balance_pay.png', 128, '点数支付备注');
INSERT INTO `la_tenant_pay_config`
VALUES (2, 0, '微信支付', 2,
        '{\"interface_version\":\"v3\",\"merchant_type\":\"ordinary_merchant\",\"mch_id\":\"\",\"pay_sign_key\":\"\",\"apiclient_cert\":\"\",\"apiclient_key\":\"\"}',
        '/resource/image/common/wechat_pay.png', 123, '微信支付备注');
INSERT INTO `la_tenant_pay_config`
VALUES (3, 0, '支付宝支付', 3,
        '{\"mode\":\"normal_mode\",\"merchant_type\":\"ordinary_merchant\",\"app_id\":\"\",\"private_key\":\"\",\"ali_public_key\":\"\"}',
        '/resource/image/common/ali_pay.png', 123, '支付宝支付');
COMMIT;

-- ----------------------------
-- Table structure for la_tenant_pay_way
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_pay_way`;
CREATE TABLE `la_tenant_pay_way`
(
    `id`            int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     int(11)          NOT NULL COMMENT '租户ID',
    `pay_config_id` int(11)          NOT NULL COMMENT '支付配置ID',
    `scene`         tinyint(1)       NOT NULL COMMENT '场景:1-微信小程序;2-微信公众号;3-H5;4-PC;5-APP;',
    `is_default`    tinyint(1)       NOT NULL DEFAULT 0 COMMENT '是否默认支付:0-否;1-是;',
    `status`        tinyint(1)       NOT NULL DEFAULT 1 COMMENT '状态:0-关闭;1-开启;',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 8
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '支付方式表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_sms_log
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_sms_log`;
CREATE TABLE `la_tenant_sms_log`
(
    `id`          int(11) NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tenant_id`   int(11) NOT NULL COMMENT '租户ID',
    `scene_id`    int(11) NOT NULL COMMENT '场景id',
    `mobile`      varchar(11)  NOT NULL COMMENT '手机号码',
    `content`     varchar(255) NOT NULL COMMENT '发送内容',
    `code`        varchar(32) DEFAULT NULL COMMENT '发送关键字（注册、找回密码）',
    `is_verify`   tinyint(1) DEFAULT '0' COMMENT '是否已验证；0-否；1-是',
    `check_num`   int(5) DEFAULT '0' COMMENT '验证次数',
    `send_status` tinyint(1) NOT NULL COMMENT '发送状态：0-发送中；1-发送成功；2-发送失败',
    `send_time`   int(10) NOT NULL COMMENT '发送时间',
    `results`     text COMMENT '短信结果',
    `create_time` int(10) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10) DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10) DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC COMMENT='租户短信记录表';

-- ----------------------------
-- Records of la_tenant_pay_way
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_pay_way`
VALUES (1, 0, 1, 1, 0, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (2, 0, 2, 1, 1, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (3, 0, 1, 2, 0, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (4, 0, 2, 2, 1, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (5, 0, 1, 3, 0, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (6, 0, 2, 3, 1, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (7, 0, 3, 3, 0, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (8, 0, 2, 4, 1, 1);
INSERT INTO `la_tenant_pay_way`
VALUES (9, 0, 3, 4, 0, 1);
COMMIT;

-- ----------------------------
-- Table structure for la_tenant_system_menu
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_system_menu`;
CREATE TABLE `la_tenant_system_menu`
(
    `id`          int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `pid`         int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '上级菜单',
    `type`        char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci      NOT NULL DEFAULT '' COMMENT '权限类型: M=目录，C=菜单，A=按钮',
    `name`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '菜单名称',
    `icon`        varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '菜单图标',
    `sort`        smallint(5) UNSIGNED                                          NOT NULL DEFAULT 0 COMMENT '菜单排序',
    `perms`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '权限标识',
    `paths`       varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '路由地址',
    `component`   varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '前端组件',
    `selected`    varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '选中路径',
    `params`      varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '路由参数',
    `is_cache`    tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否缓存: 0=否, 1=是',
    `is_show`     tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 1 COMMENT '是否显示: 0=否, 1=是',
    `is_disable`  tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否禁用: 0=否, 1=是',
    `app_code`    varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '应用标识',
    `source`      varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT 'core' COMMENT '菜单来源',
    `source_menu_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '来源菜单key',
    `is_core`     tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 1 COMMENT '是否核心菜单',
    `create_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time` int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 178
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '系统菜单表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_tenant_system_menu
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (4, 0, 0, 'M', '权限管理', 'el-icon-Lock', 300, '', 'permission', '', '', '', 0, 1, 0, 1656664556, 1710472802);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (5, 0, 0, 'C', '工作台', 'el-icon-Monitor', 1000, 'workbench/index', 'workbench', 'workbench/index', '', '', 0,
        1, 0, 1656664793, 1664354981);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (6, 0, 4, 'C', '菜单', 'el-icon-Operation', 100, 'auth.menu/lists', 'menu', 'permission/menu/index', '', '', 1,
        1, 0, 1656664960, 1710472994);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (7, 0, 4, 'C', '管理员', 'local-icon-shouyiren', 80, 'auth.admin/lists', 'admin', 'permission/admin/index', '',
        '', 0, 1, 0, 1656901567, 1710473013);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (8, 0, 4, 'C', '角色', 'el-icon-Female', 90, 'auth.role/lists', 'role', 'permission/role/index', '', '', 0, 1, 0,
        1656901660, 1710473000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (12, 0, 8, 'A', '新增', '', 1, 'auth.role/add', '', '', '', '', 0, 1, 0, 1657001790, 1663750625);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (14, 0, 8, 'A', '编辑', '', 1, 'auth.role/edit', '', '', '', '', 0, 1, 0, 1657001924, 1663750631);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (15, 0, 8, 'A', '删除', '', 1, 'auth.role/delete', '', '', '', '', 0, 1, 0, 1657001982, 1663750637);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (16, 0, 6, 'A', '新增', '', 1, 'auth.menu/add', '', '', '', '', 0, 1, 0, 1657072523, 1663750565);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (17, 0, 6, 'A', '编辑', '', 1, 'auth.menu/edit', '', '', '', '', 0, 1, 0, 1657073955, 1663750570);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (18, 0, 6, 'A', '删除', '', 1, 'auth.menu/delete', '', '', '', '', 0, 1, 0, 1657073987, 1663750578);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (19, 0, 7, 'A', '新增', '', 1, 'auth.admin/add', '', '', '', '', 0, 1, 0, 1657074035, 1663750596);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (20, 0, 7, 'A', '编辑', '', 1, 'auth.admin/edit', '', '', '', '', 0, 1, 0, 1657074071, 1663750603);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (21, 0, 7, 'A', '删除', '', 1, 'auth.admin/delete', '', '', '', '', 0, 1, 0, 1657074108, 1663750609);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (25, 0, 0, 'M', '组织管理', 'el-icon-OfficeBuilding', 400, '', 'organization', '', '', '', 0, 1, 0, 1657099914,
        1710472797);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (26, 0, 25, 'C', '部门管理', 'el-icon-Coordinate', 100, 'dept.dept/lists', 'department',
        'organization/department/index', '', '', 1, 1, 0, 1657099989, 1710472962);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (27, 0, 25, 'C', '岗位管理', 'el-icon-PriceTag', 90, 'dept.jobs/lists', 'post', 'organization/post/index', '',
        '', 0, 1, 0, 1657100044, 1710472967);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (28, 0, 0, 'M', '系统设置', 'el-icon-Setting', 200, '', 'setting', '', '', '', 0, 1, 0, 1657100164, 1710472807);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (29, 0, 28, 'M', '网站设置', 'el-icon-Basketball', 100, '', 'website', '', '', '', 0, 1, 0, 1657100230,
        1710473049);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (30, 0, 29, 'C', '网站信息', '', 1, 'setting.web.web_setting/getWebsite', 'information',
        'setting/website/information', '', '', 0, 1, 0, 1657100306, 1657164412);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (31, 0, 29, 'C', '网站备案', '', 1, 'setting.web.web_setting/getCopyright', 'filing', 'setting/website/filing',
        '', '', 0, 1, 0, 1657100434, 1657164723);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (32, 0, 29, 'C', '政策协议', '', 1, 'setting.web.web_setting/getAgreement', 'protocol',
        'setting/website/protocol', '', '', 0, 1, 0, 1657100571, 1657164770);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (35, 0, 28, 'M', '系统维护', 'el-icon-SetUp', 50, '', 'system', '', '', '', 0, 1, 0, 1657161569, 1710473122);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (37, 0, 35, 'C', '系统缓存', '', 80, '', 'cache', 'setting/system/cache', '', '', 0, 1, 0, 1657161896,
        1710473258);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (45, 0, 26, 'A', '新增', '', 1, 'dept.dept/add', '', '', '', '', 0, 1, 0, 1657163548, 1663750492);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (46, 0, 26, 'A', '编辑', '', 1, 'dept.dept/edit', '', '', '', '', 0, 1, 0, 1657163599, 1663750498);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (47, 0, 26, 'A', '删除', '', 1, 'dept.dept/delete', '', '', '', '', 0, 1, 0, 1657163687, 1663750504);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (48, 0, 27, 'A', '新增', '', 1, 'dept.jobs/add', '', '', '', '', 0, 1, 0, 1657163778, 1663750524);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (49, 0, 27, 'A', '编辑', '', 1, 'dept.jobs/edit', '', '', '', '', 0, 1, 0, 1657163800, 1663750530);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (50, 0, 27, 'A', '删除', '', 1, 'dept.jobs/delete', '', '', '', '', 0, 1, 0, 1657163820, 1663750535);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (51, 0, 30, 'A', '保存', '', 1, 'setting.web.web_setting/setWebsite', '', '', '', '', 0, 1, 0, 1657164469,
        1663750649);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (52, 0, 31, 'A', '保存', '', 1, 'setting.web.web_setting/setCopyright', '', '', '', '', 0, 1, 0, 1657164692,
        1663750657);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (53, 0, 32, 'A', '保存', '', 1, 'setting.web.web_setting/setAgreement', '', '', '', '', 0, 1, 0, 1657164824,
        1663750665);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (54, 0, 28, 'C', '存储设置', 'el-icon-FolderOpened', 70, 'setting.storage/lists', 'storage',
        'setting/storage/index', '', '', 0, 1, 0, '', 'core', 'core_tenant_storage', 1, 1657165303, 1663750673);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (55, 0, 54, 'A', '设置', '', 1, 'setting.storage/setup', '', '', '', '', 0, 1, 0, '', 'core',
        'core_tenant_storage_setup', 1, 1657165303, 1663750673);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (56, 0, 54, 'A', '切换', '', 1, 'setting.storage/change', '', '', '', '', 0, 1, 0, '', 'core',
        'core_tenant_storage_change', 1, 1657165303, 1663750673);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (57, 0, 54, 'A', '详情', '', 1, 'setting.storage/detail', '', '', '', '', 0, 1, 0, '', 'core',
        'core_tenant_storage_detail', 1, 1657165303, 1663750673);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (61, 0, 37, 'A', '清除系统缓存', '', 1, 'setting.system.cache/clear', '', '', '', '', 0, 1, 0, 1657173837,
        1657173939);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (63, 0, 158, 'M', '素材管理', 'el-icon-Picture', 0, '', 'material', '', '', '', 0, 1, 0, 1657507133, 1710472243);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (64, 0, 63, 'C', '素材中心', 'el-icon-PictureRounded', 0, '', 'index', 'material/index', '', '', 0, 1, 0,
        1657507296, 1664355653);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (66, 0, 26, 'A', '详情', '', 0, 'dept.dept/detail', '', '', '', '', 0, 1, 0, 1663725459, 1663750516);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (67, 0, 27, 'A', '详情', '', 0, 'dept.jobs/detail', '', '', '', '', 0, 1, 0, 1663725514, 1663750559);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (68, 0, 6, 'A', '详情', '', 0, 'auth.menu/detail', '', '', '', '', 0, 1, 0, 1663725564, 1663750584);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (69, 0, 7, 'A', '详情', '', 0, 'auth.admin/detail', '', '', '', '', 0, 1, 0, 1663725623, 1663750615);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (70, 0, 158, 'M', '文章资讯', 'el-icon-ChatLineSquare', 90, '', 'article', '', '', '', 0, 1, 0, 1663749965,
        1710471867);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (71, 0, 70, 'C', '文章管理', 'el-icon-ChatDotSquare', 0, 'article.article/lists', 'lists', 'article/lists/index',
        '', '', 0, 1, 0, 1663750101, 1664354615);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (72, 0, 70, 'C', '文章添加/编辑', '', 0, 'article.article/add:edit', 'lists/edit', 'article/lists/edit',
        '/article/lists', '', 0, 0, 0, 1663750153, 1664356275);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (73, 0, 70, 'C', '文章栏目', 'el-icon-CollectionTag', 0, 'article.articleCate/lists', 'column',
        'article/column/index', '', '', 1, 1, 0, 1663750287, 1664354678);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (74, 0, 71, 'A', '新增', '', 0, 'article.article/add', '', '', '', '', 0, 1, 0, 1663750335, 1663750335);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (75, 0, 71, 'A', '详情', '', 0, 'article.article/detail', '', '', '', '', 0, 1, 0, 1663750354, 1663750383);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (76, 0, 71, 'A', '删除', '', 0, 'article.article/delete', '', '', '', '', 0, 1, 0, 1663750413, 1663750413);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (77, 0, 71, 'A', '修改状态', '', 0, 'article.article/updateStatus', '', '', '', '', 0, 1, 0, 1663750442,
        1663750442);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (78, 0, 73, 'A', '添加', '', 0, 'article.articleCate/add', '', '', '', '', 0, 1, 0, 1663750483, 1663750483);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (79, 0, 73, 'A', '删除', '', 0, 'article.articleCate/delete', '', '', '', '', 0, 1, 0, 1663750895, 1663750895);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (80, 0, 73, 'A', '详情', '', 0, 'article.articleCate/detail', '', '', '', '', 0, 1, 0, 1663750913, 1663750913);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (81, 0, 73, 'A', '修改状态', '', 0, 'article.articleCate/updateStatus', '', '', '', '', 0, 1, 0, 1663750936,
        1663750936);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (82, 0, 0, 'M', '渠道设置', 'el-icon-Message', 500, '', 'channel', '', '', '', 0, 1, 0, 1663754084, 1710472649);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (83, 0, 82, 'C', 'h5设置', 'el-icon-Cellphone', 100, 'channel.web_page_setting/getConfig', 'h5', 'channel/h5',
        '', '', 0, 1, 0, 1663754158, 1710472929);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (84, 0, 83, 'A', '保存', '', 0, 'channel.web_page_setting/setConfig', '', '', '', '', 0, 1, 0, 1663754259,
        1663754259);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (85, 0, 82, 'M', '微信公众号', 'local-icon-dingdan', 80, '', 'wx_oa', '', '', '', 0, 1, 0, 1663755470,
        1710472946);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (86, 0, 85, 'C', '公众号配置', '', 0, 'channel.official_account_setting/getConfig', 'config',
        'channel/wx_oa/config', '', '', 0, 1, 0, 1663755663, 1664355450);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (87, 0, 85, 'C', '菜单管理', '', 0, 'channel.official_account_menu/detail', 'menu', 'channel/wx_oa/menu', '', '',
        0, 1, 0, 1663755767, 1664355456);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (88, 0, 86, 'A', '保存', '', 0, 'channel.official_account_setting/setConfig', '', '', '', '', 0, 1, 0,
        1663755799, 1663755799);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (89, 0, 86, 'A', '保存并发布', '', 0, 'channel.official_account_menu/save', '', '', '', '', 0, 1, 0, 1663756490,
        1663756490);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (90, 0, 85, 'C', '关注回复', '', 0, 'channel.official_account_reply/lists', 'follow',
        'channel/wx_oa/reply/follow_reply', '', '', 0, 1, 0, 1663818358, 1663818366);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (91, 0, 85, 'C', '关键字回复', '', 0, '', 'keyword', 'channel/wx_oa/reply/keyword_reply', '', '', 0, 1, 0,
        1663818445, 1663818445);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (93, 0, 85, 'C', '默认回复', '', 0, '', 'default', 'channel/wx_oa/reply/default_reply', '', '', 0, 1, 0,
        1663818580, 1663818580);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (94, 0, 82, 'C', '微信小程序', 'local-icon-weixin', 90, 'channel.mnp_settings/getConfig', 'weapp',
        'channel/weapp', '', '', 0, 1, 0, 1663831396, 1710472941);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (95, 0, 94, 'A', '保存', '', 0, 'channel.mnp_settings/setConfig', '', '', '', '', 0, 1, 0, 1663831436,
        1663831436);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (96, 0, 0, 'M', '装修管理', 'el-icon-Brush', 600, '', 'decoration', '', '', '', 0, 1, 0, 1663834825, 1710472099);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (97, 0, 175, 'C', '页面装修', 'el-icon-CopyDocument', 100, 'decorate.page/detail', 'pages',
        'decoration/pages/index', '', '', 0, 1, 0, 1663834879, 1710929256);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (98, 0, 97, 'A', '保存', '', 0, 'decorate.page/save', '', '', '', '', 0, 1, 0, 1663834956, 1663834956);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (99, 0, 175, 'C', '底部导航', 'el-icon-Position', 90, 'decorate.tabbar/detail', 'tabbar', 'decoration/tabbar',
        '', '', 0, 1, 0, 1663835004, 1710929262);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (100, 0, 99, 'A', '保存', '', 0, 'decorate.tabbar/save', '', '', '', '', 0, 1, 0, 1663835018, 1663835018);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (101, 0, 158, 'M', '消息管理', 'el-icon-ChatDotRound', 80, '', 'message', '', '', '', 0, 1, 0, 1663838602,
        1710471874);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (102, 0, 101, 'C', '通知设置', '', 0, 'notice.notice/settingLists', 'notice', 'message/notice/index', '', '', 0,
        1, 0, 1663839195, 1663839195);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (103, 0, 102, 'A', '详情', '', 0, 'notice.notice/detail', '', '', '', '', 0, 1, 0, 1663839537, 1663839537);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (104, 0, 101, 'C', '通知设置编辑', '', 0, 'notice.notice/set', 'notice/edit', 'message/notice/edit',
        '/message/notice', '', 0, 0, 0, 1663839873, 1663898477);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (105, 0, 71, 'A', '编辑', '', 0, 'article.article/edit', '', '', '', '', 0, 1, 0, 1663840043, 1663840053);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (107, 0, 101, 'C', '短信设置', '', 0, 'notice.sms_config/getConfig', 'short_letter',
        'message/short_letter/index', '', '', 0, 1, 0, 1663898591, 1664355708);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (108, 0, 107, 'A', '设置', '', 0, 'notice.sms_config/setConfig', '', '', '', '', 0, 1, 0, 1663898644,
        1663898644);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (109, 0, 107, 'A', '详情', '', 0, 'notice.sms_config/detail', '', '', '', '', 0, 1, 0, 1663898661, 1663898661);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (110, 0, 28, 'C', '热门搜索', 'el-icon-Search', 60, 'setting.hot_search/getConfig', 'search',
        'setting/search/index', '', '', 0, 1, 0, 1663901821, 1710473109);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (111, 0, 110, 'A', '保存', '', 0, 'setting.hot_search/setConfig', '', '', '', '', 0, 1, 0, 1663901856,
        1663901856);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (112, 0, 28, 'M', '用户设置', 'local-icon-keziyuyue', 90, '', 'user', '', '', '', 0, 1, 0, 1663903302,
        1710473056);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (113, 0, 112, 'C', '用户设置', '', 0, 'setting.user.user/getConfig', 'setup', 'setting/user/setup', '', '', 0, 1,
        0, 1663903506, 1663903506);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (114, 0, 113, 'A', '保存', '', 0, 'setting.user.user/setConfig', '', '', '', '', 0, 1, 0, 1663903522,
        1663903522);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (115, 0, 112, 'C', '登录注册', '', 0, 'setting.user.user/getRegisterConfig', 'login_register',
        'setting/user/login_register', '', '', 0, 1, 0, 1663903832, 1663903832);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (116, 0, 115, 'A', '保存', '', 0, 'setting.user.user/setRegisterConfig', '', '', '', '', 0, 1, 0, 1663903852,
        1663903852);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (117, 0, 0, 'M', '用户管理', 'el-icon-User', 900, '', 'consumer', '', '', '', 0, 1, 0, 1663904351, 1710472074);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (118, 0, 117, 'C', '用户列表', 'local-icon-user_guanli', 100, 'user.user/lists', 'lists', 'consumer/lists/index',
        '', '', 0, 1, 0, 1663904392, 1710471845);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (119, 0, 117, 'C', '用户详情', '', 90, 'user.user/detail', 'lists/detail', 'consumer/lists/detail',
        '/consumer/lists', '', 0, 0, 0, 1663904470, 1710471851);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (120, 0, 119, 'A', '编辑', '', 0, 'user.user/edit', '', '', '', '', 0, 1, 0, 1663904499, 1663904499);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9016, 0, 117, 'C', '任务记录', 'el-icon-List', 90, 'ai_task/lists', 'task', 'consumer/task/index',
        '', '', 0, 1, 0, '', 'core', 'core_ai_task_tenant', 1, 1727700000, 1727700000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9017, 0, 9016, 'A', '详情', '', 1, 'ai_task/detail', '', '',
        '', '', 0, 1, 0, '', 'core', 'core_ai_task_tenant_detail', 1, 1727700000, 1727700000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (140, 0, 82, 'C', '微信开放平台', 'local-icon-notice_buyer', 70, 'channel.open_setting/getConfig',
        'open_setting', 'channel/open_setting', '', '', 0, 1, 0, 1666085713, 1710472951);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (141, 0, 140, 'A', '保存', '', 0, 'channel.open_setting/setConfig', '', '', '', '', 0, 1, 0, 1666085751,
        1666085776);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (142, 0, 176, 'C', 'PC端装修', 'el-icon-Monitor', 8, '', 'pc', 'decoration/pc', '', '', 0, 0, 0, 1668423284,
        1710901602);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (148, 0, 0, 'M', '模板示例', 'el-icon-SetUp', 100, '', 'template', '', '', '', 0, 1, 0, 1670206819, 1710472811);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (149, 0, 148, 'M', '组件示例', 'el-icon-Coin', 0, '', 'component', '', '', '', 0, 1, 0, 1670207182, 1670207244);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (150, 0, 149, 'C', '富文本', '', 90, '', 'rich_text', 'template/component/rich_text', '', '', 0, 1, 0,
        1670207751, 1710473315);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (151, 0, 149, 'C', '上传文件', '', 80, '', 'upload', 'template/component/upload', '', '', 0, 1, 0, 1670208925,
        1710473322);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (152, 0, 149, 'C', '图标', '', 100, '', 'icon', 'template/component/icon', '', '', 0, 1, 0, 1670230069,
        1710473306);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (153, 0, 149, 'C', '文件选择器', '', 60, '', 'file', 'template/component/file', '', '', 0, 1, 0, 1670232129,
        1710473341);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (154, 0, 149, 'C', '链接选择器', '', 50, '', 'link', 'template/component/link', '', '', 0, 1, 0, 1670292636,
        1710473346);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (155, 0, 149, 'C', '超出自动打点', '', 40, '', 'overflow', 'template/component/overflow', '', '', 0, 1, 0,
        1670292883, 1710473351);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (156, 0, 149, 'C', '悬浮input', '', 70, '', 'popover_input', 'template/component/popover_input', '', '', 0, 1, 0,
        1670293336, 1710473329);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (157, 0, 119, 'A', '点数调整', '', 0, 'user.user/adjustMoney', '', '', '', '', 0, 1, 0, 1677143088, 1677143088);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (158, 0, 0, 'M', '应用管理', 'el-icon-Postcard', 800, '', 'app', '', '', '', 0, 1, 0, 1677143430, 1710472079);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (159, 0, 158, 'C', '用户充值', 'local-icon-fukuan', 100, 'recharge.recharge/getConfig', 'recharge',
        'app/recharge/index', '', '', 0, 1, 0, 1677144284, 1710471860);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (160, 0, 159, 'A', '保存', '', 0, 'recharge.recharge/setConfig', '', '', '', '', 0, 1, 0, 1677145012,
        1677145012);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (161, 0, 28, 'M', '支付设置', 'local-icon-set_pay', 80, '', 'pay', '', '', '', 0, 1, 0, 1677148075, 1710473061);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (162, 0, 161, 'C', '支付方式', '', 0, 'setting.pay.pay_way/getPayWay', 'method', 'setting/pay/method/index', '',
        '', 0, 1, 0, 1677148207, 1677148207);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (163, 0, 161, 'C', '支付配置', '', 0, 'setting.pay.pay_config/lists', 'config', 'setting/pay/config/index', '',
        '', 0, 1, 0, 1677148260, 1677148374);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (164, 0, 162, 'A', '设置支付方式', '', 0, 'setting.pay.pay_way/setPayWay', '', '', '', '', 0, 1, 0, 1677219624,
        1677219624);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (165, 0, 163, 'A', '配置', '', 0, 'setting.pay.pay_config/setConfig', '', '', '', '', 0, 1, 0, 1677219655,
        1677219655);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (166, 0, 0, 'M', '财务管理', 'local-icon-user_gaikuang', 700, '', 'finance', '', '', '', 0, 1, 0, 1677552269,
        1710472085);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (167, 0, 166, 'C', '充值记录', 'el-icon-Wallet', 90, 'recharge.recharge/lists', 'recharge_record',
        'finance/recharge_record', '', '', 0, 1, 0, 1677552757, 1710472902);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (168, 0, 166, 'C', '点数明细', 'local-icon-qianbao', 100, 'finance.account_log/lists', 'balance_details',
        'finance/balance_details', '', '', 0, 1, 0, 1677552976, 1710472894);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (169, 0, 167, 'A', '退款', '', 0, 'recharge.recharge/refund', '', '', '', '', 0, 1, 0, 1677809715, 1677809715);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (170, 0, 166, 'C', '退款记录', 'local-icon-heshoujilu', 0, 'finance.refund/record', 'refund_record',
        'finance/refund_record', '', '', 0, 1, 0, 1677811271, 1677811271);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (171, 0, 170, 'A', '重新退款', '', 0, 'recharge.recharge/refundAgain', '', '', '', '', 0, 1, 0, 1677811295,
        1677811295);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (172, 0, 170, 'A', '退款日志', '', 0, 'finance.refund/log', '', '', '', '', 0, 1, 0, 1677811361, 1677811361);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (173, 0, 175, 'C', '系统风格', 'el-icon-Brush', 80, '', 'style', 'decoration/style/style', '', '', 0, 1, 0,
        1681635044, 1710929278);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (175, 0, 96, 'M', '移动端', '', 100, '', 'mobile', '', '', '', 0, 1, 0, 1710901543, 1710929294);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (176, 0, 96, 'M', 'PC端', '', 90, '', 'pc', '', '', '', 0, 1, 0, 1710901592, 1710929299);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (177, 0,29, 'C', '站点统计', '', 0, 'setting.web.web_setting/getSiteStatistics', 'statistics', 'setting/website/statistics', '', '', 0, 1, 0, 1726841481, 1726843434);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
VALUES (178, 0,177, 'A', '保存', '', 0, 'setting.web.web_setting/saveSiteStatistics', '', '', '', '', 1, 1, 0, 1726841507, 1726841507);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (193, 0, 29, 'C', '网站轮播', '', 2, 'setting.web.web_banner/lists', 'banner', 'setting/website/banner', '', '', 0, 1, 0, '', 'core', 'core_tenant_website_banner', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES
(194, 0, 193, 'A', '保存', '', 0, 'setting.web.web_banner/save', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_website_banner_save', 1, 1778000000, 1778000000),
(195, 0, 193, 'A', '删除', '', 0, 'setting.web.web_banner/delete', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_website_banner_delete', 1, 1778000000, 1778000000),
(196, 0, 193, 'A', '状态', '', 0, 'setting.web.web_banner/status', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_website_banner_status', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (179, 0, 166, 'M', '套餐管理', 'el-icon-Tickets', 110, '', 'package', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_package', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (180, 0, 188, 'A', '新增', '', 0, 'finance.membership_plan/add', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan_add', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (181, 0, 188, 'A', '编辑', '', 0, 'finance.membership_plan/edit', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan_edit', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (182, 0, 188, 'A', '删除', '', 0, 'finance.membership_plan/delete', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan_delete', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (183, 0, 188, 'A', '详情', '', 0, 'finance.membership_plan/detail', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan_detail', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (184, 0, 188, 'A', '可关联应用', '', 0, 'finance.membership_plan/apps', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan_apps', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (185, 0, 166, 'C', '订单管理', 'el-icon-Document', 105, 'finance.membership_order/lists', 'membership_order', 'finance/membership_order', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_order', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (186, 0, 185, 'A', '详情', '', 0, 'finance.membership_order/detail', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_order_detail', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (187, 0, 179, 'C', '算力套餐', 'el-icon-Coin', 100, 'finance.recharge_package/lists', 'recharge_package', 'finance/recharge_package', '', '', 0, 1, 0, '', 'core', 'core_tenant_recharge_package', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (188, 0, 179, 'C', '会员套餐', 'el-icon-Medal', 90, 'finance.membership_plan/lists', 'membership_plan', 'finance/membership_plan', '', '', 0, 1, 0, '', 'core', 'core_tenant_membership_plan', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (189, 0, 187, 'A', '新增', '', 0, 'finance.recharge_package/add', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_recharge_package_add', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (190, 0, 187, 'A', '编辑', '', 0, 'finance.recharge_package/edit', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_recharge_package_edit', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (191, 0, 187, 'A', '删除', '', 0, 'finance.recharge_package/delete', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_recharge_package_delete', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (192, 0, 187, 'A', '详情', '', 0, 'finance.recharge_package/detail', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_recharge_package_detail', 1, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9000, 0, 0, 'M', '应用管理', 'el-icon-Grid', 60, '', 'apps', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_app_center', 1, 1727700000, 1727700000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9001, 0, 9000, 'C', '应用市场', 'el-icon-Shop', 100, 'app/market', 'market', 'app/market/index', '', '', 0, 1, 0, '', 'core', 'core_tenant_app_market', 1, 1727700000, 1727700000);
INSERT INTO `la_tenant_system_menu` (`id`, `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
VALUES (9002, 0, 9000, 'C', '我的应用', 'el-icon-Menu', 90, 'app/my', 'my', 'app/my/index', '', '', 0, 0, 0, '', 'core', 'core_tenant_my_app', 1, 1727700000, 1727700000);
COMMIT;

-- ----------------------------
-- Table structure for la_tenant_system_role
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_system_role`;
CREATE TABLE `la_tenant_system_role`
(
    `id`          int(11) UNSIGNED                                             NOT NULL AUTO_INCREMENT,
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `name`        varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称',
    `desc`        varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci      NOT NULL DEFAULT '' COMMENT '描述',
    `sort`        int(11)                                                      NULL     DEFAULT 0 COMMENT '排序',
    `create_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_tenant_system_role_menu
-- ----------------------------
DROP TABLE IF EXISTS `la_tenant_system_role_menu`;
CREATE TABLE `la_tenant_system_role_menu`
(
    `role_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色ID',
    `menu_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '菜单ID',
    PRIMARY KEY (`role_id`, `menu_id`) USING BTREE
) ENGINE = InnoDB
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '角色菜单关系表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_user
-- ----------------------------
DROP TABLE IF EXISTS `la_user`;
CREATE TABLE `la_user`
(
    `id`                    int(10) UNSIGNED                                              NOT NULL AUTO_INCREMENT COMMENT '主键',
    `tenant_id`             int(11)                                                       NOT NULL COMMENT '租户ID',
    `sn`                    int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '编号',
    `avatar`                varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '头像',
    `real_name`             varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '真实姓名',
    `nickname`              varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '用户昵称',
    `account`               varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '用户账号',
    `password`              varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '用户密码',
    `mobile`                varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '用户电话',
    `sex`                   tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '用户性别: [1=男, 2=女]',
    `channel`               tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '注册渠道: [1-微信小程序 2-微信公众号 3-手机H5 4-电脑PC 5-苹果APP 6-安卓APP]',
    `is_disable`            tinyint(1) UNSIGNED                                           NOT NULL DEFAULT 0 COMMENT '是否禁用: [0=否, 1=是]',
    `login_ip`              varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `login_time`            int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '最后登录时间',
    `is_new_user`           tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '是否是新注册用户: [1-是, 0-否]',
    `user_money`            decimal(10, 2) UNSIGNED                                       NULL     DEFAULT 0.00 COMMENT '用户点数',
    `total_recharge_amount` decimal(10, 2) UNSIGNED                                       NULL     DEFAULT 0.00 COMMENT '累计充值',
    `create_time`           int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '创建时间',
    `update_time`           int(10) UNSIGNED                                              NOT NULL DEFAULT 0 COMMENT '更新时间',
    `delete_time`           int(10) UNSIGNED                                              NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `sn` (`sn`) USING BTREE COMMENT '编号唯一',
    UNIQUE INDEX `account` (`account`) USING BTREE COMMENT '账号唯一'
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_user_account_log
-- ----------------------------
DROP TABLE IF EXISTS `la_user_account_log`;
CREATE TABLE `la_user_account_log`
(
    `id`            int(11) UNSIGNED                                              NOT NULL AUTO_INCREMENT,
    `tenant_id`     int(11)                                                       NOT NULL COMMENT '租户ID',
    `sn`            varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  NOT NULL DEFAULT '' COMMENT '流水号',
    `user_id`       int(11)                                                       NOT NULL COMMENT '用户id',
    `change_object` tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '变动对象',
    `change_type`   smallint(5)                                                   NOT NULL COMMENT '变动类型',
    `action`        tinyint(1)                                                    NOT NULL DEFAULT 0 COMMENT '动作 1-增加 2-减少',
    `change_amount` decimal(10, 2)                                                NOT NULL COMMENT '变动数量',
    `left_amount`   decimal(10, 2)                                                NOT NULL DEFAULT 100.00 COMMENT '变动后数量',
    `source_sn`     varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT NULL COMMENT '关联单号',
    `remark`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '备注',
    `extra`         text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci         NULL COMMENT '预留扩展字段',
    `create_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    `delete_time`   int(10)                                                       NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户账户变动记录表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_user_auth
-- ----------------------------
DROP TABLE IF EXISTS `la_user_auth`;
CREATE TABLE `la_user_auth`
(
    `id`          int(11)                                                       NOT NULL AUTO_INCREMENT,
    `tenant_id`   int(11)                                                       NOT NULL COMMENT '租户ID',
    `user_id`     int(11)                                                       NOT NULL COMMENT '用户id',
    `openid`      varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '微信openid',
    `unionid`     varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL     DEFAULT '' COMMENT '微信unionid',
    `terminal`    tinyint(1)                                                    NOT NULL DEFAULT 1 COMMENT '客户端类型：1-微信小程序；2-微信公众号；3-手机H5；4-电脑PC；5-苹果APP；6-安卓APP',
    `create_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)                                                       NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `openid` (`openid`) USING BTREE
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户授权表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Table structure for la_user_session
-- ----------------------------
DROP TABLE IF EXISTS `la_user_session`;
CREATE TABLE `la_user_session`
(
    `id`          int(11)                                                      NOT NULL AUTO_INCREMENT,
    `tenant_id`   int(11)                                                      NOT NULL COMMENT '租户ID',
    `user_id`     int(11)                                                      NOT NULL COMMENT '用户id',
    `terminal`    tinyint(1)                                                   NOT NULL DEFAULT 1 COMMENT '客户端类型：1-微信小程序；2-微信公众号；3-手机H5；4-电脑PC；5-苹果APP；6-安卓APP',
    `token`       varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '令牌',
    `update_time` int(10)                                                      NULL     DEFAULT NULL COMMENT '更新时间',
    `expire_time` int(10)                                                      NOT NULL COMMENT '到期时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `admin_id_client` (`user_id`, `terminal`) USING BTREE COMMENT '一个用户在一个终端只有一个token',
    UNIQUE INDEX `token` (`token`) USING BTREE COMMENT 'token是唯一的'
) ENGINE = InnoDB
  AUTO_INCREMENT = 1
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci COMMENT = '用户会话表'
  ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of la_user_session
-- ----------------------------

-- ----------------------------
-- Table structure for SaaS apps and aigc_image sample
-- ----------------------------
CREATE TABLE IF NOT EXISTS `la_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '应用名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '应用图标',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '应用描述',
  `category` varchar(50) NOT NULL DEFAULT 'common' COMMENT '应用分类',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '应用封面',
  `client_tags` varchar(255) NOT NULL DEFAULT '' COMMENT '适用端标签',
  `install_count` int unsigned NOT NULL DEFAULT 0 COMMENT '安装量',
  `view_count` int unsigned NOT NULL DEFAULT 0 COMMENT '浏览量',
  `is_builtin` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否内置应用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `current_version` varchar(50) NOT NULL DEFAULT '' COMMENT '当前版本',
  `status` varchar(30) NOT NULL DEFAULT 'installed' COMMENT 'installed/disabled/removed',
  `expire_policy` varchar(20) NOT NULL DEFAULT 'block' COMMENT '过期策略:block不可用 allow仍可用',
  `install_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`install_time`,`update_time`)
VALUES ('system_default','系统应用','el-icon-Setting','系统内置基础能力，包含素材、消息、文章、用户充值等默认功能。','builtin','','platform,tenant',0,0,1,1000,'1.0.0','installed',1727700000,1727700000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`install_time`,`update_time`)
VALUES ('aigc_image','AIGC生图','resource/image/common/menu_generator.png','AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.','aigc','','tenant,pc,uniapp',0,0,1,900,'1.0.0','installed',1727700000,1727700000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);
UPDATE `la_app` SET `is_builtin` = 1, `expire_policy` = 'allow', `status` = 'installed' WHERE `code` = 'aigc_image';

CREATE TABLE IF NOT EXISTS `la_app_plan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `duration_months` int unsigned NOT NULL DEFAULT 1 COMMENT '开通时长(月)',
  `open_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '开通点数',
  `renew_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '续费点数',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:1启用0禁用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_app_code` (`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用套餐';

CREATE TABLE IF NOT EXISTS `la_app_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `require_core` varchar(50) NOT NULL DEFAULT '',
  `package_path` varchar(255) NOT NULL DEFAULT '',
  `manifest_json` text,
  `changelog` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_version` (`app_code`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用版本';

CREATE TABLE IF NOT EXISTS `la_app_install` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'success',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用安装记录';

CREATE TABLE IF NOT EXISTS `la_tenant_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `buy_status` varchar(30) NOT NULL DEFAULT 'paid',
  `shelf_status` varchar(30) NOT NULL DEFAULT 'on',
  `enable_status` varchar(30) NOT NULL DEFAULT 'enabled',
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用';

CREATE TABLE IF NOT EXISTS `la_tenant_app_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `plan_id` int unsigned NOT NULL DEFAULT 0 COMMENT '套餐ID',
  `plan_name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `duration_months` int unsigned NOT NULL DEFAULT 0 COMMENT '开通时长(月)',
  `order_type` varchar(20) NOT NULL DEFAULT 'open' COMMENT 'open/renew',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `points_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '扣除点数',
  `before_expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '变更前到期时间',
  `after_expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '变更后到期时间',
  `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作人',
  `pay_status` tinyint NOT NULL DEFAULT 0,
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用订单';

CREATE TABLE IF NOT EXISTS `la_tenant_app_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(80) NOT NULL DEFAULT '' COMMENT '展示标题',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '展示描述',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `icon_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '图标资源',
  `virtual_use_count` varchar(50) NOT NULL DEFAULT '' COMMENT '虚拟使用数',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `extra` json DEFAULT NULL COMMENT '扩展配置',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用展示配置';

CREATE TABLE IF NOT EXISTS `la_app_migration` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(30) NOT NULL DEFAULT '',
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `version` varchar(50) NOT NULL DEFAULT '',
  `migration_key` varchar(120) NOT NULL DEFAULT '',
  `batch` varchar(30) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration` (`scope`,`app_code`,`tenant_id`,`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用迁移记录';

CREATE TABLE IF NOT EXISTS `la_app_api` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `api_path` varchar(255) NOT NULL DEFAULT '',
  `api_method` varchar(20) NOT NULL DEFAULT 'GET',
  `permission_key` varchar(120) NOT NULL DEFAULT '',
  `scene` varchar(30) NOT NULL DEFAULT 'tenant_admin',
  `need_login` tinyint NOT NULL DEFAULT 1,
  `need_role_permission` tinyint NOT NULL DEFAULT 1,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_api` (`app_code`,`api_path`,`api_method`,`scene`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用API声明';

CREATE TABLE IF NOT EXISTS `la_app_frontend_entry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `terminal` varchar(30) NOT NULL DEFAULT '',
  `entry_key` varchar(100) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '',
  `icon` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `meta` text,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entry` (`app_code`,`terminal`,`entry_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用前端入口';

CREATE TABLE IF NOT EXISTS `la_update_source` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '更新源名称',
  `base_url` varchar(255) NOT NULL DEFAULT '' COMMENT '授权系统接口地址',
  `license_key` varchar(255) NOT NULL DEFAULT '' COMMENT 'API Key/授权key',
  `online_base_url` varchar(255) NOT NULL DEFAULT '' COMMENT '线上授权系统接口地址',
  `online_license_key` varchar(255) NOT NULL DEFAULT '' COMMENT '线上API Key/授权key',
  `dev_mode` tinyint NOT NULL DEFAULT 0 COMMENT '开发模式：1开启 0关闭',
  `ssl_verify` tinyint NOT NULL DEFAULT 0 COMMENT 'SSL证书校验：1开启 0关闭',
  `public_key` text COMMENT '响应验签公钥',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接口渠道';

CREATE TABLE IF NOT EXISTS `la_update_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `package_id` varchar(120) NOT NULL DEFAULT '' COMMENT '远端包ID',
  `type` varchar(20) NOT NULL DEFAULT 'app' COMMENT 'system/app',
  `source` varchar(20) NOT NULL DEFAULT 'cloud' COMMENT 'cloud/upload',
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `format` varchar(20) NOT NULL DEFAULT 'zip',
  `local_path` varchar(500) NOT NULL DEFAULT '',
  `extract_path` varchar(500) NOT NULL DEFAULT '',
  `sha256` varchar(64) NOT NULL DEFAULT '',
  `package_size` bigint unsigned NOT NULL DEFAULT 0,
  `manifest_json` text,
  `status` varchar(30) NOT NULL DEFAULT 'downloaded',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_app` (`type`,`app_code`),
  KEY `idx_package` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新包记录';

CREATE TABLE IF NOT EXISTS `la_update_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL DEFAULT 'app' COMMENT 'system/app',
  `action` varchar(30) NOT NULL DEFAULT '' COMMENT 'install/update/apply',
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `preflight_json` text,
  `result_json` text,
  `error` text,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`),
  KEY `idx_package_id` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新任务记录';

CREATE TABLE IF NOT EXISTS `la_update_license` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `license_id` varchar(120) NOT NULL DEFAULT '' COMMENT '授权ID',
  `product_code` varchar(80) NOT NULL DEFAULT '' COMMENT '产品码',
  `customer_name` varchar(120) NOT NULL DEFAULT '' COMMENT '客户名称',
  `domains_json` text COMMENT '绑定域名',
  `machine_fingerprint_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '机器指纹hash',
  `license_json` text COMMENT '授权文件内容',
  `signature` text COMMENT '授权签名',
  `file_sha256` varchar(64) NOT NULL DEFAULT '' COMMENT '授权文件sha256',
  `status` varchar(30) NOT NULL DEFAULT 'active' COMMENT '状态',
  `issued_at` int unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL DEFAULT 0,
  `update_until` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_license_id` (`license_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新服务授权';

CREATE TABLE IF NOT EXISTS `la_tenant_point_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sn` varchar(32) NOT NULL DEFAULT '' COMMENT '流水编号',
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `change_type` varchar(50) NOT NULL DEFAULT '' COMMENT '变动类型',
  `action` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '动作:1增加 2减少',
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '变动点数',
  `left_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '剩余点数',
  `source_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '来源编号',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `extra` text COMMENT '扩展信息',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_sn` (`sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户点数流水';

CREATE TABLE IF NOT EXISTS `la_tenant_sso_ticket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket` varchar(64) NOT NULL DEFAULT '' COMMENT '一次性票据',
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `tenant_admin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户管理员ID',
  `platform_admin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '平台管理员ID',
  `target` varchar(30) NOT NULL DEFAULT 'admin' COMMENT '目标端',
  `redirect` varchar(255) NOT NULL DEFAULT '' COMMENT '跳转路径',
  `ip` varchar(39) NOT NULL DEFAULT '' COMMENT '发起IP',
  `user_agent` varchar(500) NOT NULL DEFAULT '' COMMENT 'UA',
  `expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '过期时间',
  `used_time` int unsigned NOT NULL DEFAULT 0 COMMENT '使用时间',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket` (`ticket`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_platform_admin` (`platform_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户后台免登录票据';

CREATE TABLE IF NOT EXISTS `la_aigc_image_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-image',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_image_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `style` varchar(50) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_image_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(255) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图结果';

CREATE TABLE IF NOT EXISTS `la_aigc_image_quota` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图额度';

CREATE TABLE IF NOT EXISTS `la_aigc_image_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图敏感词';

UPDATE `la_system_menu` SET `pid`=28,`sort`=10 WHERE `id`=4;
UPDATE `la_system_menu` SET `pid`=28,`sort`=20 WHERE `id`=25;
UPDATE `la_system_menu` SET `type`='M',`name`='系统应用',`paths`='system-default',`component`='',`icon`='el-icon-Setting',`pid`=9000,`sort`=10,`app_code`='system_default',`source`='core',`source_menu_key`='core_system_default',`is_core`=1 WHERE `id`=158;
UPDATE `la_system_menu` SET `pid`=158,`sort`=20 WHERE `id`=63;
UPDATE `la_system_menu` SET `pid`=158,`sort`=30 WHERE `id`=101;
UPDATE `la_system_menu` SET `pid`=0,`paths`='aigc-image',`sort`=90 WHERE `app_code`='aigc_image' AND `source_menu_key`='aigc_image_platform';

UPDATE `la_tenant_system_menu` SET `pid`=28,`sort`=10 WHERE `tenant_id`=0 AND `id`=4;
UPDATE `la_tenant_system_menu` SET `pid`=28,`sort`=20 WHERE `tenant_id`=0 AND `id`=25;
UPDATE `la_tenant_system_menu` SET `pid`=28,`sort`=90 WHERE `tenant_id`=0 AND `id`=148;
UPDATE `la_tenant_system_menu` SET `type`='M',`name`='系统应用',`paths`='system-default',`component`='',`icon`='el-icon-Setting',`pid`=9000,`sort`=10,`app_code`='system_default',`source`='core',`source_menu_key`='core_tenant_system_default',`is_core`=1 WHERE `tenant_id`=0 AND `id`=158;
UPDATE `la_tenant_system_menu` SET `is_show`=0 WHERE `source_menu_key`='core_tenant_my_app';
UPDATE `la_tenant_system_menu` SET `pid`=158,`sort`=10 WHERE `tenant_id`=0 AND `id`=159;
UPDATE `la_tenant_system_menu` SET `pid`=158,`sort`=20 WHERE `tenant_id`=0 AND `id`=70;
UPDATE `la_tenant_system_menu` SET `pid`=158,`sort`=30 WHERE `tenant_id`=0 AND `id`=101;
UPDATE `la_tenant_system_menu` SET `pid`=158,`sort`=40 WHERE `tenant_id`=0 AND `id`=63;
UPDATE `la_tenant_system_menu` SET `name`='模板管理',`pid`=96,`sort`=100,`perms`='decorate.template/lists',`paths`='template',`component`='decoration/template/index',`is_show`=1 WHERE `tenant_id`=0 AND `id`=97;
UPDATE `la_tenant_system_menu` SET `is_show`=0 WHERE `tenant_id`=0 AND `id` IN (99,142,173,175,176);

CREATE TABLE IF NOT EXISTS `la_decorate_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL DEFAULT 0 COMMENT '租户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '模板封面',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `is_active` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否启用',
  `publish_status` varchar(30) NOT NULL DEFAULT 'draft' COMMENT 'draft/published',
  `draft_settings` longtext COMMENT '草稿设置',
  `published_settings` longtext COMMENT '发布设置',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_active` (`tenant_id`,`is_active`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='装修模板';

ALTER TABLE `la_decorate_page` ADD COLUMN `template_id` int unsigned NOT NULL DEFAULT 0 COMMENT '模板ID' AFTER `tenant_id`;
ALTER TABLE `la_decorate_page` ADD COLUMN `terminal` varchar(20) NOT NULL DEFAULT 'mobile' COMMENT '终端 mobile/pc' AFTER `template_id`;
ALTER TABLE `la_decorate_page` ADD COLUMN `channel` varchar(20) NOT NULL DEFAULT 'common' COMMENT '渠道 common/h5/mp_weixin' AFTER `terminal`;
ALTER TABLE `la_decorate_page` ADD COLUMN `page_code` varchar(64) NOT NULL DEFAULT '' COMMENT '页面标识' AFTER `channel`;
ALTER TABLE `la_decorate_page` ADD COLUMN `page_type` varchar(30) NOT NULL DEFAULT 'custom' COMMENT '页面类型' AFTER `page_code`;
ALTER TABLE `la_decorate_page` ADD COLUMN `route_path` varchar(255) NOT NULL DEFAULT '' COMMENT '页面路径' AFTER `page_type`;
ALTER TABLE `la_decorate_page` ADD COLUMN `is_home` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否首页' AFTER `route_path`;
ALTER TABLE `la_decorate_page` ADD COLUMN `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否系统页面' AFTER `is_home`;
ALTER TABLE `la_decorate_page` ADD COLUMN `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态' AFTER `is_system`;
ALTER TABLE `la_decorate_page` ADD COLUMN `sort` int NOT NULL DEFAULT 0 COMMENT '排序' AFTER `status`;
ALTER TABLE `la_decorate_page` ADD COLUMN `draft_data` longtext COMMENT '草稿数据' AFTER `meta`;
ALTER TABLE `la_decorate_page` ADD COLUMN `draft_meta` longtext COMMENT '草稿页面设置' AFTER `draft_data`;
ALTER TABLE `la_decorate_page` ADD COLUMN `published_data` longtext COMMENT '发布数据' AFTER `draft_meta`;
ALTER TABLE `la_decorate_page` ADD COLUMN `published_meta` longtext COMMENT '发布页面设置' AFTER `published_data`;

-- ----------------------------

-- Default AIGC apps for version 1.0.6

-- ----------------------------

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`install_time`,`update_time`)
VALUES
('aigc_image','AIGC生图','resource/image/common/menu_generator.png','AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.','aigc','','tenant,pc,uniapp',0,0,1,900,'1.1.6','installed',1778000000,1778000000),
('aigc_video','AIGC视频','resource/image/common/menu_generator.png','AIGC video generation application framework for the LikeAdmin AIGC SaaS aggregation platform.','aigc','','tenant,pc,uniapp',0,0,1,900,'1.0.9','installed',1778000000,1778000000),
('aigc_digital_human','数字人视频','resource/image/common/menu_generator.png','面向移动端、PC端和后台端的数字人应用框架，支持当前用户专属形象、声音和合成视频任务。','aigc','','tenant,pc,uniapp',0,0,1,900,'1.0.1','installed',1778000000,1778000000),
('aigc_canvas','无限画布','resource/image/common/menu_generator.png','面向多节点编排创作的无限画布应用，复用生图和生视频应用完成生成能力。','aigc','','platform,tenant,pc',0,0,1,880,'1.0.1','installed',1778000000,1778000000),
('aigc_llm','AIGC对话','resource/image/common/menu_generator.png','AIGC large model conversation application with multi-session, multi-turn context and SSE streaming.','aigc','','tenant,pc,uniapp',0,0,1,880,'1.1.1','installed',1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`cover`=VALUES(`cover`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);
UPDATE `la_app` SET `is_builtin` = 1, `expire_policy` = 'allow', `status` = 'installed' WHERE `code` IN ('aigc_image','aigc_video','aigc_digital_human','aigc_canvas','aigc_llm');

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES
('aigc_image','1.1.3','>=1.0.0','local','{"code":"aigc_image","name":"AIGC生图","version":"1.1.3","require_core":">=1.0.0","description":"AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_image_admin","name":"AIGC生图","path":"/app/aigc_image","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_image","name":"AIGC生图","path":"/app/aigc_image","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_image","name":"AIGC生图","path":"/apps/aigc_image/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_image/pages/index/index"},{"name":"生图任务","path":"/apps/aigc_image/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_image/pages/results/results"}]}}]}','作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据。',1,1778000000),
('aigc_image','1.1.6','>=1.0.0','local','{"code":"aigc_image","name":"AIGC生图","version":"1.1.6","require_core":">=1.0.0","description":"AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。\n2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。\n3. 优化新模型的点数预估和任务提交体验。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_image_admin","name":"AIGC生图","path":"/app/aigc_image","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_image","name":"AIGC生图","path":"/app/aigc_image","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_image","name":"AIGC生图","path":"/apps/aigc_image/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_image/pages/index/index"},{"name":"生图任务","path":"/apps/aigc_image/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_image/pages/results/results"}]}}]}','1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。
2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。
3. 优化新模型的点数预估和任务提交体验。',1,1778000000),
('aigc_video','1.0.7','>=1.0.0','local','{"code":"aigc_video","name":"AIGC视频","version":"1.0.7","require_core":">=1.0.0","description":"AIGC video generation application framework for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"1. 同步视频创作端可选时长到后台通道价格配置。\n2. 修复动态时长通道生成时按实际时长匹配价格规格。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_video","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_video_admin","name":"AIGC视频","path":"/app/aigc_video","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_video","name":"AIGC视频","path":"/app/aigc_video","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_video","name":"AIGC视频","path":"/apps/aigc_video/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_video/pages/index/index"},{"name":"视频任务","path":"/apps/aigc_video/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_video/pages/results/results"}]}}]}','1. 同步视频创作端可选时长到后台通道价格配置。\n2. 修复动态时长通道生成时按实际时长匹配价格规格。',1,1778000000),
('aigc_video','1.0.9','>=1.0.0','local','{"code":"aigc_video","name":"AIGC视频","version":"1.0.9","require_core":">=1.0.0","description":"AIGC video generation application framework for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"1. Seedance 2.0 Pro 支持 Pro 和 Fast 两种模式分别配置秒单价。\n2. 优化后台规格价格展示，比例和时长作为生成参数，不再展开为价格规格。\n3. 修复 Seedance 2.0 Pro 生成和预估时模式价格匹配不准确的问题。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_video","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_video_admin","name":"AIGC视频","path":"/app/aigc_video","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_video","name":"AIGC视频","path":"/app/aigc_video","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_video","name":"AIGC视频","path":"/apps/aigc_video/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_video/pages/index/index"},{"name":"视频任务","path":"/apps/aigc_video/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_video/pages/results/results"}]}}]}','1. Seedance 2.0 Pro 支持 Pro 和 Fast 两种模式分别配置秒单价。\n2. 优化后台规格价格展示，比例和时长作为生成参数，不再展开为价格规格。\n3. 修复 Seedance 2.0 Pro 生成和预估时模式价格匹配不准确的问题。',1,1778000000),
('aigc_digital_human','1.0.1','>=1.0.0','local','{"code":"aigc_digital_human","name":"数字人视频","version":"1.0.1","require_core":">=1.0.0","description":"面向移动端、PC端和后台端的数字人应用框架，支持当前用户专属形象、声音和合成视频任务。","changelog":"作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据，并修复完整安装时编排字段迁移重复执行问题。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_digital_human","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_digital_human_admin","name":"数字人视频","path":"/app/aigc_digital_human","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_digital_human","name":"数字人视频","path":"/app/aigc_digital_human","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_digital_human","name":"数字人视频","path":"/apps/aigc_digital_human/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_digital_human/pages/index/index"},{"name":"选择形象","path":"/apps/aigc_digital_human/pages/assets/avatar/avatar"},{"name":"选择声音","path":"/apps/aigc_digital_human/pages/assets/voice/voice"},{"name":"克隆形象","path":"/apps/aigc_digital_human/pages/clone/avatar/avatar"},{"name":"克隆音色","path":"/apps/aigc_digital_human/pages/clone/voice/voice"},{"name":"合成任务","path":"/apps/aigc_digital_human/pages/tasks/tasks"},{"name":"创作记录","path":"/apps/aigc_digital_human/pages/results/results"},{"name":"记录详情","path":"/apps/aigc_digital_human/pages/results/detail/detail"}]}}]}','作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据，并修复完整安装时编排字段迁移重复执行问题。',1,1778000000),
('aigc_canvas','1.0.1','>=1.0.0','local','{"code":"aigc_canvas","name":"无限画布","version":"1.0.1","require_core":">=1.0.0","description":"面向多节点编排创作的无限画布应用，复用生图和生视频应用完成生成能力。","changelog":"作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","sort":880,"frontends":["platform","tenant","pc"],"api_prefix":"/app/aigc_canvas","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"platform","entry_key":"aigc_canvas_platform","name":"无限画布","path":"/app/aigc_canvas","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"tenant","entry_key":"aigc_canvas_admin","name":"无限画布","path":"/app/aigc_canvas","icon":"el-icon-Share","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_canvas","name":"无限画布","path":"/app/aigc_canvas","icon":"resource/image/common/menu_generator.png","sort":95,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片生成"},{"app_code":"aigc_video","name":"AIGC视频","required_for":"视频生成"}]}','作为系统默认AIGC应用随新装系统预安装启用；补齐默认租户与新租户自动开通、上架和会员套餐关联数据。',1,1778000000),
('aigc_llm','1.1.1','>=1.0.0','local','{"code":"aigc_llm","name":"AIGC对话","version":"1.1.1","require_core":">=1.0.0","description":"AIGC large model conversation application with multi-session, multi-turn context and SSE streaming.","changelog":"作为系统默认AIGC基础应用随新装系统预安装启用；保留Token计费、OpenAI兼容流式通道和默认Qwen3.6-Plus模型能力。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","sort":880,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_llm","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_llm_admin","name":"AIGC对话","path":"/app/aigc_llm","icon":"el-icon-ChatDotRound","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_llm","name":"AIGC对话","path":"/app/aigc_llm","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_llm","name":"AIGC对话","path":"/apps/aigc_llm/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"对话首页","path":"/apps/aigc_llm/pages/index/index"}]}}]}','作为系统默认AIGC基础应用随新装系统预安装启用；保留Token计费、OpenAI兼容流式通道和默认Qwen3.6-Plus模型能力。',1,1778000000)
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_image','tenant','aigc_image_admin','AIGC生图','/app/aigc_image','el-icon-Picture',100,1,'{}',1778000000),
('aigc_image','pc','aigc_image','AIGC生图','/app/aigc_image','resource/image/common/menu_generator.png',100,1,'{}',1778000000),
('aigc_image','uniapp','aigc_image','AIGC生图','/apps/aigc_image/pages/index/index','resource/image/common/menu_generator.png',100,1,'{"pages":[{"name":"创作首页","path":"/apps/aigc_image/pages/index/index"},{"name":"生图任务","path":"/apps/aigc_image/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_image/pages/results/results"}]}',1778000000),
('aigc_video','tenant','aigc_video_admin','AIGC视频','/app/aigc_video','el-icon-Picture',100,1,'{}',1778000000),
('aigc_video','pc','aigc_video','AIGC视频','/app/aigc_video','resource/image/common/menu_generator.png',100,1,'{}',1778000000),
('aigc_video','uniapp','aigc_video','AIGC视频','/apps/aigc_video/pages/index/index','resource/image/common/menu_generator.png',100,1,'{"pages":[{"name":"创作首页","path":"/apps/aigc_video/pages/index/index"},{"name":"视频任务","path":"/apps/aigc_video/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_video/pages/results/results"}]}',1778000000),
('aigc_digital_human','tenant','aigc_digital_human_admin','数字人视频','/app/aigc_digital_human','el-icon-Picture',100,1,'{}',1778000000),
('aigc_digital_human','pc','aigc_digital_human','数字人视频','/app/aigc_digital_human','resource/image/common/menu_generator.png',100,1,'{}',1778000000),
('aigc_digital_human','uniapp','aigc_digital_human','数字人视频','/apps/aigc_digital_human/pages/index/index','resource/image/common/menu_generator.png',100,1,'{"pages":[{"name":"创作首页","path":"/apps/aigc_digital_human/pages/index/index"},{"name":"选择形象","path":"/apps/aigc_digital_human/pages/assets/avatar/avatar"},{"name":"选择声音","path":"/apps/aigc_digital_human/pages/assets/voice/voice"},{"name":"克隆形象","path":"/apps/aigc_digital_human/pages/clone/avatar/avatar"},{"name":"克隆音色","path":"/apps/aigc_digital_human/pages/clone/voice/voice"},{"name":"合成任务","path":"/apps/aigc_digital_human/pages/tasks/tasks"},{"name":"创作记录","path":"/apps/aigc_digital_human/pages/results/results"},{"name":"记录详情","path":"/apps/aigc_digital_human/pages/results/detail/detail"}]}',1778000000),
('aigc_canvas','platform','aigc_canvas_platform','无限画布','/app/aigc_canvas','resource/image/common/menu_generator.png',100,1,'{}',1778000000),
('aigc_canvas','tenant','aigc_canvas_admin','无限画布','/app/aigc_canvas','el-icon-Share',100,1,'{}',1778000000),
('aigc_canvas','pc','aigc_canvas','无限画布','/app/aigc_canvas','resource/image/common/menu_generator.png',95,1,'{}',1778000000),
('aigc_llm','tenant','aigc_llm_admin','AIGC对话','/app/aigc_llm','el-icon-ChatDotRound',100,1,'{}',1778000000),
('aigc_llm','pc','aigc_llm','AIGC对话','/app/aigc_llm','resource/image/common/menu_generator.png',100,1,'{}',1778000000),
('aigc_llm','uniapp','aigc_llm','AIGC对话','/apps/aigc_llm/pages/index/index','resource/image/common/menu_generator.png',100,1,'{"pages":[{"name":"对话首页","path":"/apps/aigc_llm/pages/index/index"}]}',1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_image','app.aigc_image.config/detail','GET','aigc_image:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.config/setup','POST','aigc_image:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.config/detail','GET','aigc_image:config:detail:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.config/setup','POST','aigc_image:config:setup:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.tenant/stat','GET','aigc_image:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin_task/lists','GET','aigc_image:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin_task/detail','GET','aigc_image:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin_task/retry','POST','aigc_image:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin_task/delete','POST','aigc_image:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/lists','GET','aigc_image:case:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/detail','GET','aigc_image:case:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/save','POST','aigc_image:case:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/fromTask','POST','aigc_image:case:from_task','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/status','POST','aigc_image:case:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/delete','POST','aigc_image:case:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin/quota','GET','aigc_image:quota:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin/quota','POST','aigc_image:quota:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin/sensitiveWord','GET','aigc_image:sensitive_word:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin/sensitiveWord','POST','aigc_image:sensitive_word:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.admin/stat','GET','aigc_image:stat','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/lists','GET','aigc_image:channel_price:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/save','POST','aigc_image:channel_price:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/batchSave','POST','aigc_image:channel_price:batch_save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/status','POST','aigc_image:channel_price:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/lists','GET','aigc_image:channel:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/save','POST','aigc_image:channel:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/delete','POST','aigc_image:channel:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.channel/status','POST','aigc_image:channel:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.config/detail','GET','aigc_image:config:user','user',0,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.case/lists','GET','aigc_image:case:lists:user','user',0,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.generate/index','POST','aigc_image:generate','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.generate/estimate','POST','aigc_image:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.task/detail','GET','aigc_image:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.task/lists','GET','aigc_image:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.result/lists','GET','aigc_image:result:lists','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.result/delete','POST','aigc_image:result:delete','user',1,0,1,1778000000,1778000000),
('aigc_image','app.aigc_image.spec/lists','GET','aigc_image:spec:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.spec/save','POST','aigc_image:spec:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.spec/batchSave','POST','aigc_image:spec:batch_save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.spec/delete','POST','aigc_image:spec:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_image','app.aigc_image.spec/status','POST','aigc_image:spec:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.config/detail','GET','aigc_video:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.config/setup','POST','aigc_video:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.config/detail','GET','aigc_video:config:detail:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.config/setup','POST','aigc_video:config:setup:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.tenant/stat','GET','aigc_video:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin_task/lists','GET','aigc_video:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin_task/detail','GET','aigc_video:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin_task/retry','POST','aigc_video:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin_task/delete','POST','aigc_video:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/lists','GET','aigc_video:case:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/detail','GET','aigc_video:case:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/save','POST','aigc_video:case:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/fromTask','POST','aigc_video:case:from_task','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/status','POST','aigc_video:case:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/delete','POST','aigc_video:case:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin/quota','GET','aigc_video:quota:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin/quota','POST','aigc_video:quota:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin/sensitiveWord','GET','aigc_video:sensitive_word:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin/sensitiveWord','POST','aigc_video:sensitive_word:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.admin/stat','GET','aigc_video:stat','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/lists','GET','aigc_video:channel_price:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/save','POST','aigc_video:channel_price:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/batchSave','POST','aigc_video:channel_price:batch_save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/status','POST','aigc_video:channel_price:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/lists','GET','aigc_video:channel:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/save','POST','aigc_video:channel:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/delete','POST','aigc_video:channel:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.channel/status','POST','aigc_video:channel:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.config/detail','GET','aigc_video:config:user','user',0,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.case/lists','GET','aigc_video:case:lists:user','user',0,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.generate/index','POST','aigc_video:generate','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.generate/estimate','POST','aigc_video:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.task/detail','GET','aigc_video:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.task/lists','GET','aigc_video:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.result/lists','GET','aigc_video:result:lists','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.result/delete','POST','aigc_video:result:delete','user',1,0,1,1778000000,1778000000),
('aigc_video','app.aigc_video.spec/lists','GET','aigc_video:spec:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.spec/save','POST','aigc_video:spec:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.spec/batchSave','POST','aigc_video:spec:batch_save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.spec/delete','POST','aigc_video:spec:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_video','app.aigc_video.spec/status','POST','aigc_video:spec:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.config/detail','GET','aigc_digital_human:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.config/setup','POST','aigc_digital_human:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.config/detail','GET','aigc_digital_human:config:detail:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.config/setup','POST','aigc_digital_human:config:setup:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.tenant/stat','GET','aigc_digital_human:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin_task/lists','GET','aigc_digital_human:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin_task/detail','GET','aigc_digital_human:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin_task/retry','POST','aigc_digital_human:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin_task/delete','POST','aigc_digital_human:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/lists','GET','aigc_digital_human:case:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/detail','GET','aigc_digital_human:case:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/save','POST','aigc_digital_human:case:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/fromTask','POST','aigc_digital_human:case:from_task','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/status','POST','aigc_digital_human:case:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/delete','POST','aigc_digital_human:case:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_avatar/lists','GET','aigc_digital_human:public_avatar:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_avatar/save','POST','aigc_digital_human:public_avatar:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_avatar/delete','POST','aigc_digital_human:public_avatar:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_voice/lists','GET','aigc_digital_human:public_voice:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_voice/save','POST','aigc_digital_human:public_voice:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.public_voice/delete','POST','aigc_digital_human:public_voice:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.user_avatar/lists','GET','aigc_digital_human:user_avatar:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.user_avatar/delete','POST','aigc_digital_human:user_avatar:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.user_voice/lists','GET','aigc_digital_human:user_voice:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.user_voice/publish','POST','aigc_digital_human:user_voice:publish','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.user_voice/delete','POST','aigc_digital_human:user_voice:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin/quota','GET','aigc_digital_human:quota:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin/quota','POST','aigc_digital_human:quota:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin/sensitiveWord','GET','aigc_digital_human:sensitive_word:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin/sensitiveWord','POST','aigc_digital_human:sensitive_word:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.admin/stat','GET','aigc_digital_human:stat','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/lists','GET','aigc_digital_human:channel_price:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/save','POST','aigc_digital_human:channel_price:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/status','POST','aigc_digital_human:channel_price:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/lists','GET','aigc_digital_human:channel:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/save','POST','aigc_digital_human:channel:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/delete','POST','aigc_digital_human:channel:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.channel/status','POST','aigc_digital_human:channel:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.task_log/lists','GET','aigc_digital_human:task_log:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.task_log/detail','GET','aigc_digital_human:task_log:detail','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.config/detail','GET','aigc_digital_human:config:user','user',0,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.case/lists','GET','aigc_digital_human:case:lists:user','user',0,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.generate/index','POST','aigc_digital_human:generate','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.generate/estimate','POST','aigc_digital_human:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.avatar/lists','GET','aigc_digital_human:avatar:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.avatar/save','POST','aigc_digital_human:avatar:save:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.voice/lists','GET','aigc_digital_human:voice:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.voice/save','POST','aigc_digital_human:voice:save:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.task/detail','GET','aigc_digital_human:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.task/lists','GET','aigc_digital_human:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.result/lists','GET','aigc_digital_human:result:lists','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.result/delete','POST','aigc_digital_human:result:delete','user',1,0,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.spec/lists','GET','aigc_digital_human:spec:lists','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.spec/save','POST','aigc_digital_human:spec:save','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.spec/delete','POST','aigc_digital_human:spec:delete','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.spec/status','POST','aigc_digital_human:spec:status','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.pricing/detail','GET','aigc_digital_human:pricing:detail:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.pricing/setup','POST','aigc_digital_human:pricing:setup:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.pricing/detail','GET','aigc_digital_human:pricing:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_digital_human','app.aigc_digital_human.pricing/setup','POST','aigc_digital_human:pricing:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.admin/stat','GET','aigc_canvas:stat','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.admin_project/lists','GET','aigc_canvas:project:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.admin_project/delete','POST','aigc_canvas:project:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.admin_project/clear','POST','aigc_canvas:project:clear','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.admin_run/lists','GET','aigc_canvas:run:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.config/detail','GET','aigc_canvas:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.config/setup','POST','aigc_canvas:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.config/dependencies','GET','aigc_canvas:dependencies','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.tenant/stat','GET','aigc_canvas:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.tenant/lists','GET','aigc_canvas:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.config/dependencies','GET','aigc_canvas:dependencies:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.config/detail','GET','aigc_canvas:config:user','user',0,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/lists','GET','aigc_canvas:project:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/create','POST','aigc_canvas:project:create','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/detail','GET','aigc_canvas:project:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/save','POST','aigc_canvas:project:save','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/rename','POST','aigc_canvas:project:rename','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/duplicate','POST','aigc_canvas:project:duplicate','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.project/delete','POST','aigc_canvas:project:delete:user','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.run/lists','GET','aigc_canvas:run:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.generate/image','POST','aigc_canvas:generate:image','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.generate/video','POST','aigc_canvas:generate:video','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.generate/imageQuery','GET','aigc_canvas:generate:image_query','user',1,0,1,1778000000,1778000000),
('aigc_canvas','app.aigc_canvas.generate/videoQuery','GET','aigc_canvas:generate:video_query','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.config/detail','GET','aigc_llm:config:user','user',0,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.session/lists','GET','aigc_llm:session:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.session/create','POST','aigc_llm:session:create:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.session/detail','GET','aigc_llm:session:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.session/rename','POST','aigc_llm:session:rename:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.session/delete','POST','aigc_llm:session:delete:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.message/lists','GET','aigc_llm:message:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.chat/stream','POST','aigc_llm:chat:stream:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.chat/stop','POST','aigc_llm:chat:stop:user','user',1,0,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.config/detail','GET','aigc_llm:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.config/setup','POST','aigc_llm:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/lists','GET','aigc_llm:channel:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/save','POST','aigc_llm:channel:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/status','POST','aigc_llm:channel:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/lists','GET','aigc_llm:model:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/save','POST','aigc_llm:model:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/status','POST','aigc_llm:model:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.admin_session/lists','GET','aigc_llm:session:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.admin_session/detail','GET','aigc_llm:session:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.admin/stat','GET','aigc_llm:stat','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.admin/sensitiveWord','GET','aigc_llm:sensitive_word:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.admin/sensitiveWord','POST','aigc_llm:sensitive_word:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.config/detail','GET','aigc_llm:config:detail:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.config/setup','POST','aigc_llm:config:setup:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/lists','GET','aigc_llm:channel:lists:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/save','POST','aigc_llm:channel:save:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/delete','POST','aigc_llm:channel:delete:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.channel/status','POST','aigc_llm:channel:status:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/lists','GET','aigc_llm:model:lists:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/save','POST','aigc_llm:model:save:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/delete','POST','aigc_llm:model:delete:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.model/status','POST','aigc_llm:model:status:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_llm','app.aigc_llm.tenant/stat','GET','aigc_llm:tenant_usage:platform','platform_admin',1,1,1,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
VALUES
(0,'aigc_image','1.1.6','paid','on','enabled',0,1778000000,1778000000),
(0,'aigc_video','1.0.1','paid','on','enabled',0,1778000000,1778000000),
(0,'aigc_digital_human','1.0.1','paid','on','enabled',0,1778000000,1778000000),
(0,'aigc_canvas','1.0.1','paid','on','enabled',0,1778000000,1778000000),
(0,'aigc_llm','1.1.1','paid','on','enabled',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_image/migrations/zz_20260503_app_case.sql

CREATE TABLE IF NOT EXISTS `la_app_case` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '案例标题',
  `prompt` text COMMENT '提示词',
  `media_type` varchar(20) NOT NULL DEFAULT 'image' COMMENT 'image/video',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `media_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '作品资源',
  `reference_images` text COMMENT '参考图',
  `config_json` text COMMENT '生成参数',
  `source_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源任务ID',
  `source_result_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源作品ID',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_app` (`tenant_id`,`app_code`,`delete_time`,`status`,`sort`),
  KEY `idx_source` (`tenant_id`,`app_code`,`source_task_id`,`source_result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用案例广场';


-- Migration snapshot: aigc_image/migrations/zz_20260506_membership.sql

CREATE TABLE IF NOT EXISTS `la_membership_plan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '套餐简介',
  `monthly_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付价格',
  `yearly_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付价格',
  `monthly_market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付划线价',
  `yearly_market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付划线价',
  `monthly_bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付赠送积分',
  `yearly_bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付赠送积分',
  `features` text COMMENT '权益说明',
  `is_recommend` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否推荐',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐';

CREATE TABLE IF NOT EXISTS `la_recharge_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '到账点数',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价',
  `market_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '划线价',
  `is_recommend` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否推荐',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='算力套餐';

CREATE TABLE IF NOT EXISTS `la_membership_plan_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_app` (`plan_id`,`app_code`),
  KEY `idx_tenant_app` (`tenant_id`,`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐关联应用';

CREATE TABLE IF NOT EXISTS `la_membership_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `pay_sn` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `plan_name` varchar(100) NOT NULL DEFAULT '',
  `cycle` varchar(20) NOT NULL DEFAULT 'monthly',
  `duration_months` int unsigned NOT NULL DEFAULT 1,
  `order_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `before_expire_time` int unsigned NOT NULL DEFAULT 0,
  `after_expire_time` int unsigned NOT NULL DEFAULT 0,
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`pay_status`),
  KEY `idx_plan` (`tenant_id`,`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员订单';

CREATE TABLE IF NOT EXISTS `la_user_membership` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `plan_name` varchar(100) NOT NULL DEFAULT '',
  `app_codes` text COMMENT '可用应用',
  `features` text COMMENT '权益快照',
  `start_time` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `source_order_sn` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_expire` (`tenant_id`,`expire_time`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会员权益';

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `recharge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''到账点数'' AFTER `order_amount`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'recharge_points');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''充值套餐ID'' AFTER `recharge_points`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'package_id');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `package_name` varchar(100) NOT NULL DEFAULT '''' COMMENT ''充值套餐名称'' AFTER `package_id`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'package_name');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

DELETE FROM `la_membership_plan_app`
WHERE `app_code` IN ('aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_canvas', 'aigc_llm', 'aigc_hairstyle', 'aigc_fitting', 'aigc_product_image', 'aigc_style_transfer', 'aigc_photo_restore', 'aigc_model_wear', 'aigc_background_removal', 'aigc_image_translate', 'aigc_one_click_cleanup', 'aigc_product_suite', 'aigc_product_multi_angle', 'aigc_fashion_lookbook', 'aigc_product_promo_video', 'aigc_outpaint', 'aigc_local_redraw');

INSERT INTO `la_membership_plan` (
  `tenant_id`,
  `name`,
  `description`,
  `monthly_price`,
  `yearly_price`,
  `monthly_market_price`,
  `yearly_market_price`,
  `monthly_bonus_points`,
  `yearly_bonus_points`,
  `features`,
  `is_recommend`,
  `status`,
  `sort`,
  `create_time`,
  `update_time`
)
SELECT
  `id`,
  '免费会员',
  '系统默认免费会员，默认AIGC应用可直接使用',
  0.00,
  0.00,
  0.00,
  0.00,
  0.00,
  0.00,
  '["默认AIGC应用永久免费使用","可购买积分继续创作","会员权益可由租户继续调整"]',
  0,
  1,
  100,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_tenant` t
WHERE NOT EXISTS (
  SELECT 1 FROM `la_membership_plan` p
  WHERE p.`tenant_id` = t.`id` AND p.`name` = '免费会员'
);

INSERT INTO `la_membership_plan` (
  `tenant_id`,
  `name`,
  `description`,
  `monthly_price`,
  `yearly_price`,
  `monthly_market_price`,
  `yearly_market_price`,
  `monthly_bonus_points`,
  `yearly_bonus_points`,
  `features`,
  `is_recommend`,
  `status`,
  `sort`,
  `create_time`,
  `update_time`
)
SELECT
  t.`id`,
  plans.`name`,
  plans.`description`,
  plans.`monthly_price`,
  plans.`yearly_price`,
  plans.`monthly_market_price`,
  plans.`yearly_market_price`,
  plans.`monthly_bonus_points`,
  plans.`yearly_bonus_points`,
  plans.`features`,
  plans.`is_recommend`,
  1,
  plans.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN (
  SELECT '基础会员' AS `name`, '适合轻量创作用户，赠送基础积分' AS `description`, 19.90 AS `monthly_price`, 199.00 AS `yearly_price`, 29.90 AS `monthly_market_price`, 299.00 AS `yearly_market_price`, 100.00 AS `monthly_bonus_points`, 1500.00 AS `yearly_bonus_points`, '["每月赠送100积分","按年开通赠送1500积分","适合个人轻量创作"]' AS `features`, 0 AS `is_recommend`, 90 AS `sort`
  UNION ALL
  SELECT '高级会员', '适合高频创作用户，赠送更多积分', 39.90, 399.00, 69.90, 699.00, 300.00, 4200.00, '["每月赠送300积分","按年开通赠送4200积分","适合高频图文与视频创作"]', 1, 80
) plans
WHERE NOT EXISTS (
  SELECT 1 FROM `la_membership_plan` p
  WHERE p.`tenant_id` = t.`id` AND p.`name` = plans.`name`
);

INSERT INTO `la_recharge_package` (
  `tenant_id`,
  `name`,
  `points`,
  `amount`,
  `market_amount`,
  `is_recommend`,
  `status`,
  `sort`,
  `create_time`,
  `update_time`
)
SELECT
  t.`id`,
  packages.`name`,
  packages.`points`,
  packages.`amount`,
  packages.`market_amount`,
  packages.`is_recommend`,
  1,
  packages.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN (
  SELECT '体验包' AS `name`, 10.00 AS `points`, 10.00 AS `amount`, 0.00 AS `market_amount`, 0 AS `is_recommend`, 100 AS `sort`
  UNION ALL
  SELECT '轻量包', 30.00, 30.00, 0.00, 0, 90
  UNION ALL
  SELECT '标准包', 50.00, 50.00, 0.00, 0, 80
  UNION ALL
  SELECT '进阶包', 100.00, 100.00, 0.00, 1, 70
  UNION ALL
  SELECT '专业包', 300.00, 300.00, 0.00, 0, 60
  UNION ALL
  SELECT '团队包', 500.00, 500.00, 0.00, 0, 50
) packages
WHERE NOT EXISTS (
  SELECT 1 FROM `la_recharge_package` p
  WHERE p.`tenant_id` = t.`id` AND p.`name` = packages.`name`
);


-- Migration snapshot: aigc_image/migrations/zz_20260503_channel_billing.sql

CREATE TABLE IF NOT EXISTS `la_aigc_image_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-image' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 4 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图通道';

CREATE TABLE IF NOT EXISTS `la_aigc_image_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '分辨率名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '图片比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '上游成本单价',
  `platform_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '平台供给单价',
  `tenant_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '租户用户售价',
  `upstream_cost_text` varchar(500) NOT NULL DEFAULT '' COMMENT '上游成本说明',
  `cost_source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '成本来源链接',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_image_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图扣费明细';

ALTER TABLE `la_aigc_image_task`
  ADD COLUMN `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道' AFTER `style`,
  ADD COLUMN `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位' AFTER `channel`,
  ADD COLUMN `reference_images` text COMMENT '参考图' AFTER `negative_prompt`,
  ADD COLUMN `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点' AFTER `quantity`,
  ADD COLUMN `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点' AFTER `tenant_cost_points`,
  ADD COLUMN `provider` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商' AFTER `user_charge_points`,
  ADD COLUMN `model` varchar(100) NOT NULL DEFAULT '' COMMENT '模型' AFTER `provider`,
  ADD COLUMN `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID' AFTER `model`;

ALTER TABLE `la_aigc_image_result`
  ADD COLUMN `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道' AFTER `user_id`,
  ADD COLUMN `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位' AFTER `channel`,
  ADD COLUMN `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '图片比例' AFTER `quality`,
  ADD COLUMN `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点' AFTER `height`,
  ADD COLUMN `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点' AFTER `tenant_cost_points`,
  ADD COLUMN `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID' AFTER `user_charge_points`;

INSERT INTO `la_aigc_image_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2','GPT Image 2','xhadmin','gpt-image-2',4,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"OpenaiM"}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2','1k','标准1K','1:1',1024,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"1:1"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','16:9',1024,576,30.00,30.00,'{"resolution":"1k","aspect_ratio":"16:9"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','9:16',576,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"9:16"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','4:3',1024,768,30.00,30.00,'{"resolution":"1k","aspect_ratio":"4:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','3:4',768,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"3:4"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','3:2',1024,682,30.00,30.00,'{"resolution":"1k","aspect_ratio":"3:2"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','2:3',682,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"2:3"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','5:4',1024,819,30.00,30.00,'{"resolution":"1k","aspect_ratio":"5:4"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','4:5',819,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"4:5"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','2:1',1024,512,30.00,30.00,'{"resolution":"1k","aspect_ratio":"2:1"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','1:2',512,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"1:2"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','21:9',1024,439,30.00,30.00,'{"resolution":"1k","aspect_ratio":"21:9"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','9:21',439,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"9:21"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','1:1',2048,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"1:1"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','16:9',2048,1152,60.00,60.00,'{"resolution":"2k","aspect_ratio":"16:9"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','9:16',1152,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"9:16"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','4:3',2048,1536,60.00,60.00,'{"resolution":"2k","aspect_ratio":"4:3"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','3:4',1536,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"3:4"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','3:2',2048,1365,60.00,60.00,'{"resolution":"2k","aspect_ratio":"3:2"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','2:3',1365,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"2:3"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','5:4',2048,1638,60.00,60.00,'{"resolution":"2k","aspect_ratio":"5:4"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','4:5',1638,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"4:5"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','2:1',2048,1024,60.00,60.00,'{"resolution":"2k","aspect_ratio":"2:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','1:2',1024,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"1:2"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','21:9',2048,878,60.00,60.00,'{"resolution":"2k","aspect_ratio":"21:9"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','9:21',878,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"9:21"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','1:1',4096,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:1"}',1,745,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','16:9',4096,2304,120.00,120.00,'{"resolution":"4k","aspect_ratio":"16:9"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:16',2304,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:16"}',1,735,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','4:3',4096,3072,120.00,120.00,'{"resolution":"4k","aspect_ratio":"4:3"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','3:4',3072,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"3:4"}',1,725,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','3:2',4096,2731,120.00,120.00,'{"resolution":"4k","aspect_ratio":"3:2"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','2:3',2731,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"2:3"}',1,715,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','5:4',4096,3277,120.00,120.00,'{"resolution":"4k","aspect_ratio":"5:4"}',1,712,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','2:1',4096,2048,120.00,120.00,'{"resolution":"4k","aspect_ratio":"2:1"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','4:5',3277,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"4:5"}',1,705,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','21:9',4096,1755,120.00,120.00,'{"resolution":"4k","aspect_ratio":"21:9"}',1,700,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:21',1755,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:21"}',1,695,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','1:2',2048,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:2"}',1,690,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);


-- Migration snapshot: aigc_image/migrations/zz_20260616_gpt_image_2_pro_fast_channels.sql
INSERT INTO `la_aigc_image_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2_pro','GPT Image 2 Pro','gpt_image_2_pro','gpt-image-2-pro',16,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"OpenaiM","quantity_options":[1],"ratio_options":["auto","1:1","3:2","2:3","4:3","3:4","5:4","4:5","16:9","9:16","2:1","1:2","3:1","1:3","21:9","9:21"]}',1,620,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','GPT Image 2 Fast','gpt_image_2_fast','gpt-image-2-fast',16,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"openaiD","quantity_options":[1],"ratio_options":["1:1","3:2","2:3","4:3","3:4","5:4","4:5","16:9","9:16","2:1","1:2","3:1","1:3","21:9","9:21"]}',1,610,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `provider`=VALUES(`provider`),
  `model`=VALUES(`model`),
  `max_reference_images`=VALUES(`max_reference_images`),
  `config_json`=VALUES(`config_json`),
  `status`=IF(`status` IN (0,1), `status`, VALUES(`status`)),
  `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
  `update_time`=UNIX_TIMESTAMP();

UPDATE `la_app` SET `current_version`='1.1.6', `update_time`=UNIX_TIMESTAMP() WHERE `code`='aigc_image';

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_image','1.1.6','>=1.0.0','local','{"code":"aigc_image","name":"AIGC生图","version":"1.1.6","require_core":">=1.0.0","description":"AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。\n2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。\n3. 优化新模型的点数预估和任务提交体验。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_image_admin","name":"AIGC生图","path":"/app/aigc_image","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_image","name":"AIGC生图","path":"/app/aigc_image","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_image","name":"AIGC生图","path":"/apps/aigc_image/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_image/pages/index/index"},{"name":"生图任务","path":"/apps/aigc_image/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_image/pages/results/results"}]}}]}','1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。
2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。
3. 优化新模型的点数预估和任务提交体验。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

DELETE FROM `la_aigc_image_channel_spec`
WHERE `tenant_id` = 0
  AND `channel_code` IN ('gpt_image_2_pro','gpt_image_2_fast');

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`cost_source_url`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2_pro','1k','1K','default',0,0,0.0000,30.0000,30.0000,'1K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"1k","omit_resolution":true}',1,1300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_pro','2k','2K','default',0,0,0.0000,60.0000,60.0000,'2K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"2k","omit_resolution":true}',1,1290,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_pro','4k','4K','default',0,0,0.0000,120.0000,120.0000,'4K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"4k","omit_resolution":true}',1,1280,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','1k','1K','default',0,0,0.0000,30.0000,30.0000,'1K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"1k","omit_resolution":true}',1,1270,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','2k','2K','default',0,0,0.0000,60.0000,60.0000,'2K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"2k","omit_resolution":true}',1,1260,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','4k','4K','default',0,0,0.0000,120.0000,120.0000,'4K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"4k","omit_resolution":true}',1,1250,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- Migration snapshot: aigc_video/migrations/install.sql

-- AIGC video application business tables.
-- Core app-center/update tables belong to system migrations, not this app package.

CREATE TABLE IF NOT EXISTS `la_aigc_video_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-video',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频配置';

CREATE TABLE IF NOT EXISTS `la_aigc_video_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `reference_images` text COMMENT '参考图',
  `style` varchar(50) NOT NULL DEFAULT '',
  `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `provider` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT '' COMMENT '模型',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频任务';

CREATE TABLE IF NOT EXISTS `la_aigc_video_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '视频比例',
  `video_uri` varchar(255) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频结果';

CREATE TABLE IF NOT EXISTS `la_aigc_video_quota` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频额度';

CREATE TABLE IF NOT EXISTS `la_aigc_video_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频敏感词';


-- Migration snapshot: aigc_video/migrations/zz_20260503_app_case.sql

CREATE TABLE IF NOT EXISTS `la_app_case` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '案例标题',
  `prompt` text COMMENT '提示词',
  `media_type` varchar(20) NOT NULL DEFAULT 'image' COMMENT 'image/video',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `media_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '作品资源',
  `reference_images` text COMMENT '参考图',
  `config_json` text COMMENT '生成参数',
  `source_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源任务ID',
  `source_result_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源作品ID',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_app` (`tenant_id`,`app_code`,`delete_time`,`status`,`sort`),
  KEY `idx_source` (`tenant_id`,`app_code`,`source_task_id`,`source_result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用案例广场';


-- Migration snapshot: aigc_video/migrations/zz_20260503_channel_billing.sql

CREATE TABLE IF NOT EXISTS `la_aigc_video_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-video' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 4 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频通道';

CREATE TABLE IF NOT EXISTS `la_aigc_video_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '视频时长名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '视频比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '上游成本单价',
  `platform_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '平台供给单价',
  `tenant_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '租户用户售价',
  `upstream_cost_text` varchar(500) NOT NULL DEFAULT '' COMMENT '上游成本说明',
  `cost_source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '成本来源链接',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_video_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频扣费明细';

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"duration_options":[6,10,15,20,25,30],"quality":"720p","supported_asset_types":["image"],"max_reference_images":7,"max_reference_assets":7}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','6','6秒','16:9',1280,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','9:16',720,1280,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','1:1',720,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','2:3',720,1080,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"2:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','3:2',1080,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"3:2"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','16:9',1280,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','9:16',720,1280,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','1:1',720,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','2:3',720,1080,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"2:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','3:2',1080,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"3:2"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','16:9',1280,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','9:16',720,1280,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','1:1',720,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','2:3',720,1080,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"2:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','3:2',1080,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"3:2"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','16:9',1280,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','9:16',720,1280,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','1:1',720,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','2:3',720,1080,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"2:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','3:2',1080,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"3:2"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','16:9',1280,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"16:9"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','9:16',720,1280,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"9:16"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','1:1',720,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"1:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','2:3',720,1080,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"2:3"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','3:2',1080,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"3:2"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','16:9',1280,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"16:9"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','9:16',720,1280,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"9:16"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','1:1',720,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"1:1"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','2:3',720,1080,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"2:3"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','3:2',1080,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"3:2"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` <> 'grok_video_xaiq';

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `channel_code` <> 'grok_video_xaiq';


-- Migration snapshot: aigc_video/migrations/zz_20260506_gpt_video_channel.sql

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"duration_options":[6,10,15,20,25,30],"quality":"720p","supported_asset_types":["image"],"max_reference_images":7,"max_reference_assets":7}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','6','6秒','16:9',1280,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','9:16',720,1280,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','1:1',720,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','2:3',720,1080,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"2:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','3:2',1080,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"3:2"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','16:9',1280,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','9:16',720,1280,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','1:1',720,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','2:3',720,1080,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"2:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','3:2',1080,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"3:2"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','16:9',1280,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','9:16',720,1280,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','1:1',720,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','2:3',720,1080,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"2:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','3:2',1080,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"3:2"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','16:9',1280,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','9:16',720,1280,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','1:1',720,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','2:3',720,1080,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"2:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','3:2',1080,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"3:2"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','16:9',1280,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"16:9"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','9:16',720,1280,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"9:16"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','1:1',720,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"1:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','2:3',720,1080,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"2:3"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','3:2',1080,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"3:2"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','16:9',1280,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"16:9"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','9:16',720,1280,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"9:16"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','1:1',720,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"1:1"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','2:3',720,1080,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"2:3"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','3:2',1080,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"3:2"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` <> 'grok_video_xaiq';

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `channel_code` <> 'grok_video_xaiq';


-- Migration snapshot: aigc_digital_human/migrations/install.sql

-- Digital human application business tables.
-- Core app-center/update tables belong to system migrations, not this app package.

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-digital-human',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人配置';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_avatar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方形象',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `scene` varchar(50) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `media_uri` varchar(500) NOT NULL DEFAULT '',
  `media_type` varchar(20) NOT NULL DEFAULT 'image',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`source`,`delete_time`),
  KEY `idx_provider` (`tenant_id`,`provider`,`provider_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人形象资产';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_voice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方声音',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `age_group` varchar(20) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `duration` int unsigned NOT NULL DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`source`,`delete_time`),
  KEY `idx_provider` (`tenant_id`,`provider`,`provider_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人声音资产';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `script_text` text,
  `prompt` text,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `duration` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `model` varchar(100) NOT NULL DEFAULT '',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `provider_stage` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商编排阶段',
  `tts_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT 'TTS供应商任务ID',
  `tts_audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT 'TTS音频地址',
  `provider_payload_json` text COMMENT '供应商阶段载荷',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_provider_task` (`tenant_id`,`provider`,`provider_task_id`),
  KEY `idx_tts_task` (`tenant_id`,`provider`,`tts_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人合成任务';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人合成结果';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_quota` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人额度';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人敏感词';


-- Migration snapshot: aigc_digital_human/migrations/zz_20260503_app_case.sql

CREATE TABLE IF NOT EXISTS `la_app_case` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '案例标题',
  `prompt` text COMMENT '提示词',
  `media_type` varchar(20) NOT NULL DEFAULT 'image' COMMENT 'image/video',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `media_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '作品资源',
  `reference_images` text COMMENT '参考图',
  `config_json` text COMMENT '生成参数',
  `source_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源任务ID',
  `source_result_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源作品ID',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_app` (`tenant_id`,`app_code`,`delete_time`,`status`,`sort`),
  KEY `idx_source` (`tenant_id`,`app_code`,`source_task_id`,`source_result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用案例广场';


-- Migration snapshot: aigc_digital_human/migrations/zz_20260503_channel_billing.sql

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-digital-human' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 1 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人通道';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '分辨率名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '画面比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `billing_unit` varchar(20) NOT NULL DEFAULT 'second' COMMENT '计费单位 second/count',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `billing_type` varchar(30) NOT NULL DEFAULT 'generate' COMMENT '计费类型 generate/avatar_clone/voice_clone',
  `billing_unit` varchar(20) NOT NULL DEFAULT 'count' COMMENT '计费单位 second/count',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `extra_json` text COMMENT '计费扩展信息',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人扣费明细';

INSERT INTO `la_aigc_digital_human_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','大师版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','全能版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','体验版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_digital_human_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`billing_unit`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','16:9',2048,1152,0.40,0.60,'second','{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','9:16',1152,2048,0.40,0.60,'second','{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','16:9',2048,1152,0.40,0.60,'second','{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','9:16',1152,2048,0.40,0.60,'second','{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`billing_unit`=VALUES(`billing_unit`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);


-- Migration snapshot: aigc_digital_human/migrations/zz_20260505_model_description_fix.sql

UPDATE `la_aigc_digital_human_channel`
SET `config_json` = JSON_SET(
    CASE
        WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
          AND JSON_TYPE(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)) = 'OBJECT'
        THEN CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)
        ELSE JSON_OBJECT()
    END,
    '$.description',
    CASE `code`
        WHEN 'master' THEN '高质量数字人视频模型，适合正式口播和营销内容'
        WHEN 'all' THEN '通用数字人视频模型，适合产品介绍和知识讲解'
        WHEN 'free' THEN '轻量体验模型，适合快速试用和短文案预览'
        ELSE '标准数字人视频模型'
    END
)
WHERE `tenant_id` = 0
  AND (
    NOT JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
    OR JSON_TYPE(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)) <> 'OBJECT'
    OR JSON_UNQUOTE(JSON_EXTRACT(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON), '$.description')) IS NULL
    OR JSON_UNQUOTE(JSON_EXTRACT(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON), '$.description')) = ''
  );


-- Migration snapshot: aigc_digital_human/migrations/zz_20260505_model_pricing.sql

INSERT INTO `la_aigc_digital_human_config` (`tenant_id`,`provider_mode`,`provider`,`model`,`config_json`,`status`,`create_time`,`update_time`)
SELECT
  0,
  'platform',
  'xhadmin',
  'xiaojiayu1.0',
  JSON_OBJECT(
    'pricing',
    JSON_OBJECT(
      'generate_models',
      JSON_ARRAYAGG(
        JSON_OBJECT(
          'code',
          `code`,
          'platform_unit_cost',
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.generate.platform_unit_cost')), '0.20'),
          'tenant_unit_price',
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.generate.tenant_unit_price')), '0.30')
        )
      ),
      'avatar_clone',
      COALESCE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.avatar_clone'), JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00)),
      'voice_clone',
      COALESCE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.voice_clone'), JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00))
    )
  ),
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_aigc_digital_human_channel`
WHERE `tenant_id` = 0
ON DUPLICATE KEY UPDATE
`config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.pricing.generate_models',
  COALESCE(
    JSON_EXTRACT(`config_json`, '$.pricing.generate_models'),
    JSON_EXTRACT(VALUES(`config_json`), '$.pricing.generate_models')
  )
),
`update_time` = UNIX_TIMESTAMP();


-- Migration snapshot: aigc_digital_human/migrations/zz_20260505_unified_pricing.sql

INSERT INTO `la_aigc_digital_human_config` (`tenant_id`,`provider_mode`,`provider`,`model`,`config_json`,`status`,`create_time`,`update_time`)
VALUES (
  0,
  'platform',
  'xhadmin',
  'xiaojiayu1.0',
  JSON_OBJECT(
    'pricing',
    JSON_OBJECT(
      'generate',
      JSON_OBJECT(
        'platform_unit_cost',
        COALESCE((SELECT `platform_unit_cost` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = 1 ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.20),
        'tenant_unit_price',
        COALESCE((SELECT `tenant_unit_price` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = 1 ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.30)
      ),
      'avatar_clone',
      JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00),
      'voice_clone',
      JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00)
    )
  ),
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE
`config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.pricing.generate',
  COALESCE(
    JSON_EXTRACT(`config_json`, '$.pricing.generate'),
    JSON_OBJECT(
      'platform_unit_cost',
      COALESCE((SELECT `platform_unit_cost` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = 1 ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.20),
      'tenant_unit_price',
      COALESCE((SELECT `tenant_unit_price` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = 1 ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.30)
    )
  ),
  '$.pricing.avatar_clone',
  COALESCE(JSON_EXTRACT(`config_json`, '$.pricing.avatar_clone'), JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00)),
  '$.pricing.voice_clone',
  COALESCE(JSON_EXTRACT(`config_json`, '$.pricing.voice_clone'), JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00))
),
`update_time` = UNIX_TIMESTAMP();


-- Migration snapshot: aigc_canvas/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_project` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `name` varchar(120) NOT NULL DEFAULT '未命名项目' COMMENT '项目名称',
  `thumbnail` varchar(500) NOT NULL DEFAULT '' COMMENT '缩略图',
  `nodes_json` longtext COMMENT '节点JSON',
  `edges_json` longtext COMMENT '边JSON',
  `viewport_json` text COMMENT '视口JSON',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_tenant_update` (`tenant_id`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='无限画布项目';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT '画布项目ID',
  `node_id` varchar(120) NOT NULL DEFAULT '' COMMENT '节点ID',
  `run_type` varchar(30) NOT NULL DEFAULT '' COMMENT 'image/video/text/workflow',
  `source_app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '调用应用',
  `source_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联任务ID',
  `status` varchar(30) NOT NULL DEFAULT 'running' COMMENT 'running/success/failed',
  `prompt` text COMMENT '提示词',
  `params_json` text COMMENT '调用参数',
  `result_json` text COMMENT '执行结果',
  `error` text COMMENT '错误信息',
  `duration_ms` int unsigned NOT NULL DEFAULT 0 COMMENT '耗时',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_project` (`tenant_id`,`project_id`,`delete_time`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_source_task` (`source_app_code`,`source_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='无限画布运行记录';


-- Migration snapshot: aigc_llm/migrations/install.sql

-- AIGC LLM conversation application business tables.

CREATE TABLE IF NOT EXISTS `la_aigc_llm_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `model` varchar(100) NOT NULL DEFAULT 'qwen3_6_plus',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话配置';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话通道';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_model` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `channel_code` varchar(64) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `model` varchar(100) NOT NULL DEFAULT '',
  `context_limit` int unsigned NOT NULL DEFAULT 12,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_channel` (`tenant_id`,`channel_code`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话模型';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_word` (`tenant_id`,`word`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话敏感词';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_session` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(100) NOT NULL DEFAULT '',
  `model_code` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'idle',
  `last_message_at` int unsigned NOT NULL DEFAULT 0,
  `message_count` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_last_message` (`tenant_id`,`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话会话';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `session_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `content` mediumtext,
  `seq` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'done',
  `finish_reason` varchar(50) NOT NULL DEFAULT '',
  `token_usage_json` text,
  `parent_user_message_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_session_seq` (`tenant_id`,`session_id`,`seq`),
  KEY `idx_parent_user` (`tenant_id`,`parent_user_message_id`),
  KEY `idx_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话消息';

INSERT INTO `la_aigc_llm_config`
(`tenant_id`, `provider_mode`, `provider`, `model`, `config_json`, `status`, `create_time`, `update_time`)
SELECT 0, 'platform', 'openai_compatible', 'qwen3_6_plus', '{"system_prompt":"","max_context_messages":12,"auto_title_chars":18}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_config` WHERE `tenant_id` = 0);

INSERT INTO `la_aigc_llm_channel`
(`tenant_id`, `code`, `name`, `provider`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'dashscope_compatible', 'Qwen3.6-Plus 兼容通道', 'openai_compatible', '{"base_url":"","stream_path":"/api/v1/chat/completions","api_key":"","timeout":120,"ssl_verify":0,"remark":"Qwen3.6-Plus OpenAI compatible"}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_channel` WHERE `tenant_id` = 0 AND `code` = 'dashscope_compatible');

INSERT INTO `la_aigc_llm_model`
(`tenant_id`, `channel_code`, `code`, `name`, `provider`, `model`, `context_limit`, `platform_unit_cost`, `tenant_unit_price`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'dashscope_compatible', 'qwen3_6_plus', 'Qwen3.6-Plus', 'openai_compatible', 'qwen3.6-plus', 24, 200.00, 200.00, '{"temperature":0.7,"max_tokens":8192,"enable_thinking":false,"stream_options":{"include_usage":true}}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_model` WHERE `tenant_id` = 0 AND `code` = 'qwen3_6_plus');

-- Migration snapshot: aigc_llm/migrations/zz_20260508_token_billing.sql

-- Token billing and default OpenAI-compatible Qwen channel for AIGC LLM.

SET @aigc_llm_table = 'la_aigc_llm_model';
SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_input_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台输入成本，点/百万Token'' AFTER `tenant_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_input_unit_cost');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_output_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台输出成本，点/百万Token'' AFTER `platform_input_unit_cost`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_output_unit_cost');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `tenant_input_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''用户输入售价，点/百万Token'' AFTER `platform_output_unit_cost`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'tenant_input_unit_price');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `tenant_output_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''用户输出售价，点/百万Token'' AFTER `tenant_input_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'tenant_output_unit_price');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `billing_unit` varchar(30) NOT NULL DEFAULT ''tokens_1m'' COMMENT ''计费单位'' AFTER `tenant_output_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'billing_unit');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_usage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `session_id` int unsigned NOT NULL DEFAULT 0,
  `message_id` int unsigned NOT NULL DEFAULT 0,
  `channel_code` varchar(64) NOT NULL DEFAULT '',
  `model_code` varchar(64) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_model` varchar(100) NOT NULL DEFAULT '',
  `provider_request_id` varchar(120) NOT NULL DEFAULT '',
  `prompt_tokens` int unsigned NOT NULL DEFAULT 0,
  `completion_tokens` int unsigned NOT NULL DEFAULT 0,
  `total_tokens` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '租户成本扣点',
  `user_charge_points` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `price_json` text,
  `extra_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`tenant_id`,`message_id`),
  KEY `idx_session` (`tenant_id`,`session_id`),
  KEY `idx_user_time` (`tenant_id`,`user_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话Token用量扣费明细';

UPDATE `la_aigc_llm_model`
SET `platform_input_unit_cost` = CASE WHEN `platform_input_unit_cost` = 0 THEN `platform_unit_cost` ELSE `platform_input_unit_cost` END,
    `platform_output_unit_cost` = CASE WHEN `platform_output_unit_cost` = 0 THEN `platform_unit_cost` ELSE `platform_output_unit_cost` END,
    `tenant_input_unit_price` = CASE WHEN `tenant_input_unit_price` = 0 THEN `tenant_unit_price` ELSE `tenant_input_unit_price` END,
    `tenant_output_unit_price` = CASE WHEN `tenant_output_unit_price` = 0 THEN `tenant_unit_price` ELSE `tenant_output_unit_price` END,
    `billing_unit` = 'tokens_1m',
    `update_time` = UNIX_TIMESTAMP();

INSERT INTO `la_aigc_llm_channel`
(`tenant_id`, `code`, `name`, `provider`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
VALUES
(0, 'dashscope_compatible', 'Qwen3.6-Plus 兼容通道', 'openai_compatible', '{"base_url":"","stream_path":"/api/v1/chat/completions","api_key":"","timeout":120,"ssl_verify":0,"remark":"Qwen3.6-Plus OpenAI compatible"}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`name`=VALUES(`name`),
`provider`=VALUES(`provider`),
`config_json`=JSON_SET(
  VALUES(`config_json`),
  '$.api_key',
  COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.api_key')), ''), '')
),
`status`=VALUES(`status`),
`sort`=VALUES(`sort`),
`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_llm_model`
(`tenant_id`, `channel_code`, `code`, `name`, `provider`, `model`, `context_limit`, `platform_unit_cost`, `tenant_unit_price`, `platform_input_unit_cost`, `platform_output_unit_cost`, `tenant_input_unit_price`, `tenant_output_unit_price`, `billing_unit`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
VALUES
(0, 'dashscope_compatible', 'qwen3_6_plus', 'Qwen3.6-Plus', 'openai_compatible', 'qwen3.6-plus', 24, 200.00, 200.00, 200.0000, 1200.0000, 200.0000, 1200.0000, 'tokens_1m', '{"temperature":0.7,"max_tokens":8192,"enable_thinking":false,"stream_options":{"include_usage":true}}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `channel_code`=VALUES(`channel_code`), `name`=VALUES(`name`), `provider`=VALUES(`provider`), `model`=VALUES(`model`), `context_limit`=VALUES(`context_limit`), `platform_unit_cost`=VALUES(`platform_unit_cost`), `tenant_unit_price`=VALUES(`tenant_unit_price`), `platform_input_unit_cost`=VALUES(`platform_input_unit_cost`), `platform_output_unit_cost`=VALUES(`platform_output_unit_cost`), `tenant_input_unit_price`=VALUES(`tenant_input_unit_price`), `tenant_output_unit_price`=VALUES(`tenant_output_unit_price`), `billing_unit`=VALUES(`billing_unit`), `config_json`=VALUES(`config_json`), `status`=VALUES(`status`), `sort`=VALUES(`sort`), `update_time`=VALUES(`update_time`);

UPDATE `la_aigc_llm_config`
SET `provider_mode` = 'platform',
    `provider` = 'openai_compatible',
    `model` = 'qwen3_6_plus',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0;

UPDATE `la_aigc_llm_config`
SET `provider` = 'openai_compatible',
    `model` = 'qwen3_6_plus',
    `update_time` = UNIX_TIMESTAMP()
WHERE `provider` = 'mock' OR `model` IN ('mock_chat_basic', 'mock_chat_fast');

DELETE FROM `la_aigc_llm_model`
WHERE `provider` = 'mock' OR `code` IN ('mock_chat_basic', 'mock_chat_fast');

DELETE FROM `la_aigc_llm_channel`
WHERE `provider` = 'mock' OR `code` = 'mock_llm';

-- Default app platform menus

INSERT INTO `la_system_menu` (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9100,0,'M','AIGC生图','el-icon-Picture',90,'','aigc-image','','','',0,1,0,'aigc_image','app','aigc_image_platform',0,1778000000,1778000000),
(9101,9100,'C','通道管理','',0,'app.aigc_image.channel/lists','channel','apps/aigc_image/channel','','',0,1,0,'aigc_image','app','aigc_image_platform_channel',0,1778000000,1778000000),
(9102,9100,'C','规格价格','',0,'app.aigc_image.spec/lists','spec','apps/aigc_image/spec','','',0,1,0,'aigc_image','app','aigc_image_platform_spec',0,1778000000,1778000000),
(9103,9100,'C','租户用量','',0,'app.aigc_image.tenant/stat','tenant-usage','apps/aigc_image/tenant-usage','','',0,1,0,'aigc_image','app','aigc_image_platform_tenant_usage',0,1778000000,1778000000);

INSERT INTO `la_system_menu` (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9109,0,'M','AIGC视频','el-icon-Picture',90,'','aigc-video','','','',0,1,0,'aigc_video','app','aigc_video_platform',0,1778000000,1778000000),
(9110,9109,'C','通道管理','',0,'app.aigc_video.channel/lists','channel','apps/aigc_video/channel','','',0,1,0,'aigc_video','app','aigc_video_platform_channel',0,1778000000,1778000000),
(9111,9109,'C','规格价格','',0,'app.aigc_video.spec/lists','spec','apps/aigc_video/spec','','',0,1,0,'aigc_video','app','aigc_video_platform_spec',0,1778000000,1778000000),
(9112,9109,'C','租户用量','',0,'app.aigc_video.tenant/stat','tenant-usage','apps/aigc_video/tenant-usage','','',0,1,0,'aigc_video','app','aigc_video_platform_tenant_usage',0,1778000000,1778000000);

INSERT INTO `la_system_menu` (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9118,0,'M','数字人视频','el-icon-Picture',90,'','aigc-digital-human','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_platform',0,1778000000,1778000000),
(9119,9118,'C','计费配置','',0,'app.aigc_digital_human.channel/lists','channel','apps/aigc_digital_human/channel','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_platform_channel',0,1778000000,1778000000),
(9120,9118,'C','任务日志','',0,'app.aigc_digital_human.task_log/lists','task-log','apps/aigc_digital_human/task-log','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_platform_task_log',0,1778000000,1778000000),
(9121,9118,'C','租户用量','',0,'app.aigc_digital_human.tenant/stat','tenant-usage','apps/aigc_digital_human/tenant-usage','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_platform_tenant_usage',0,1778000000,1778000000);

INSERT INTO `la_system_menu` (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9133,0,'M','无限画布','el-icon-Share',88,'','aigc-canvas','','','',0,1,0,'aigc_canvas','app','aigc_canvas_platform',0,1778000000,1778000000),
(9134,9133,'C','租户用量','',0,'app.aigc_canvas.tenant/stat','tenant-usage','apps/aigc_canvas/tenant-usage','','',0,1,0,'aigc_canvas','app','aigc_canvas_platform_tenant_usage',0,1778000000,1778000000),
(9135,9133,'C','依赖状态','',0,'app.aigc_canvas.config/dependencies','dependencies','apps/aigc_canvas/dependencies','','',0,1,0,'aigc_canvas','app','aigc_canvas_platform_dependency',0,1778000000,1778000000);

INSERT INTO `la_system_menu` (`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9141,0,'M','AIGC对话','el-icon-ChatDotRound',88,'','aigc-llm','','','',0,1,0,'aigc_llm','app','aigc_llm_platform',0,1778000000,1778000000),
(9142,9141,'C','基础配置','',0,'app.aigc_llm.config/detail','config','apps/aigc_llm/config','','',0,1,0,'aigc_llm','app','aigc_llm_platform_config',0,1778000000,1778000000),
(9143,9141,'C','通道管理','',0,'app.aigc_llm.channel/lists','channel','apps/aigc_llm/channel','','',0,1,0,'aigc_llm','app','aigc_llm_platform_channel',0,1778000000,1778000000),
(9144,9141,'C','模型管理','',0,'app.aigc_llm.model/lists','model','apps/aigc_llm/model','','',0,1,0,'aigc_llm','app','aigc_llm_platform_model',0,1778000000,1778000000),
(9145,9141,'C','租户统计','',0,'app.aigc_llm.tenant/stat','tenant-usage','apps/aigc_llm/tenant-usage','','',0,1,0,'aigc_llm','app','aigc_llm_platform_tenant_usage',0,1778000000,1778000000);

-- Default app tenant menus for template tenant

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9104,0,0,'M','AIGC生图','el-icon-Picture',100,'','aigc-image','','','',0,1,0,'aigc_image','app','aigc_image',0,1778000000,1778000000),
(9105,0,9104,'C','生图任务','',0,'app.aigc_image.admin_task/lists','task','apps/aigc_image/task','','',0,1,0,'aigc_image','app','aigc_image_task',0,1778000000,1778000000),
(9106,0,0,'C','案例广场','el-icon-PictureFilled',98,'app.aigc_image.case/lists','aigc-image-case','apps/aigc_image/case','aigc-image-case','',0,1,0,'aigc_image','app','aigc_image_case',0,1778000000,1778000000),
(9107,0,9104,'C','通道调价','',0,'app.aigc_image.channel/lists','channel-price','apps/aigc_image/channel-price','','',0,1,0,'aigc_image','app','aigc_image_channel_price',0,1778000000,1778000000),
(9108,0,9104,'C','用量统计','',0,'app.aigc_image.admin/stat','stat','apps/aigc_image/stat','','',0,1,0,'aigc_image','app','aigc_image_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9113,0,0,'M','AIGC视频','el-icon-Picture',100,'','aigc-video','','','',0,1,0,'aigc_video','app','aigc_video',0,1778000000,1778000000),
(9114,0,9113,'C','视频任务','',0,'app.aigc_video.admin_task/lists','task','apps/aigc_video/task','','',0,1,0,'aigc_video','app','aigc_video_task',0,1778000000,1778000000),
(9115,0,9113,'C','案例广场','',0,'app.aigc_video.case/lists','case','apps/aigc_video/case','','',0,1,0,'aigc_video','app','aigc_video_case',0,1778000000,1778000000),
(9116,0,9113,'C','通道调价','',0,'app.aigc_video.channel/lists','channel-price','apps/aigc_video/channel-price','','',0,1,0,'aigc_video','app','aigc_video_channel_price',0,1778000000,1778000000),
(9117,0,9113,'C','用量统计','',0,'app.aigc_video.admin/stat','stat','apps/aigc_video/stat','','',0,1,0,'aigc_video','app','aigc_video_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9122,0,0,'M','数字人视频','el-icon-Picture',100,'','aigc-digital-human','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human',0,1778000000,1778000000),
(9123,0,9122,'C','合成任务','',0,'app.aigc_digital_human.admin_task/lists','task','apps/aigc_digital_human/task','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_task',0,1778000000,1778000000),
(9124,0,9122,'C','案例广场','',0,'app.aigc_digital_human.case/lists','case','apps/aigc_digital_human/case','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_case',0,1778000000,1778000000),
(9125,0,9122,'M','形象管理','',0,'','avatar-manage','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_avatar_manage',0,1778000000,1778000000),
(9126,0,9125,'C','公共形象','',0,'app.aigc_digital_human.public_avatar/lists','public-avatar','apps/aigc_digital_human/public-avatar','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_public_avatar',0,1778000000,1778000000),
(9127,0,9125,'C','用户形象','',0,'app.aigc_digital_human.user_avatar/lists','user-avatar','apps/aigc_digital_human/user-avatar','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_user_avatar',0,1778000000,1778000000),
(9128,0,9122,'M','音色管理','',0,'','voice-manage','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_voice_manage',0,1778000000,1778000000),
(9129,0,9128,'C','公共音色','',0,'app.aigc_digital_human.public_voice/lists','public-voice','apps/aigc_digital_human/public-voice','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_public_voice',0,1778000000,1778000000),
(9130,0,9128,'C','用户音色','',0,'app.aigc_digital_human.user_voice/lists','user-voice','apps/aigc_digital_human/user-voice','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_user_voice',0,1778000000,1778000000),
(9131,0,9122,'C','通道调价','',0,'app.aigc_digital_human.channel/lists','channel-price','apps/aigc_digital_human/channel-price','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_channel_price',0,1778000000,1778000000),
(9132,0,9122,'C','用量统计','',0,'app.aigc_digital_human.admin/stat','stat','apps/aigc_digital_human/stat','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9136,0,0,'M','无限画布','el-icon-Share',96,'','aigc-canvas','','','',0,1,0,'aigc_canvas','app','aigc_canvas',0,1778000000,1778000000),
(9137,0,9136,'C','用量统计','',0,'app.aigc_canvas.admin/stat','stat','apps/aigc_canvas/stat','','',0,1,0,'aigc_canvas','app','aigc_canvas_stat',0,1778000000,1778000000),
(9138,0,9136,'C','项目管理','',0,'app.aigc_canvas.admin_project/lists','project','apps/aigc_canvas/project','','',0,1,0,'aigc_canvas','app','aigc_canvas_project',0,1778000000,1778000000),
(9139,0,9136,'C','运行记录','',0,'app.aigc_canvas.admin_run/lists','run','apps/aigc_canvas/run','','',0,1,0,'aigc_canvas','app','aigc_canvas_run',0,1778000000,1778000000),
(9140,0,9136,'C','依赖状态','',0,'app.aigc_canvas.config/dependencies','dependencies','apps/aigc_canvas/dependencies','','',0,1,0,'aigc_canvas','app','aigc_canvas_dependency',0,1778000000,1778000000);


INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9146,0,0,'M','AIGC对话','el-icon-ChatDotRound',100,'','aigc-llm','','','',0,1,0,'aigc_llm','app','aigc_llm',0,1778000000,1778000000),
(9147,0,9146,'C','基础配置','',0,'app.aigc_llm.config/detail','config','apps/aigc_llm/config','','',0,1,0,'aigc_llm','app','aigc_llm_config',0,1778000000,1778000000),
(9148,0,9146,'C','通道配置','',0,'app.aigc_llm.channel/lists','channel','apps/aigc_llm/channel','','',0,1,0,'aigc_llm','app','aigc_llm_channel',0,1778000000,1778000000),
(9149,0,9146,'C','模型配置','',0,'app.aigc_llm.model/lists','model','apps/aigc_llm/model','','',0,1,0,'aigc_llm','app','aigc_llm_model',0,1778000000,1778000000),
(9150,0,9146,'C','会话记录','',0,'app.aigc_llm.admin_session/lists','session','apps/aigc_llm/session','','',0,1,0,'aigc_llm','app','aigc_llm_session',0,1778000000,1778000000),
(9151,0,9146,'C','敏感词','',0,'app.aigc_llm.admin/sensitiveWord','sensitive-word','apps/aigc_llm/sensitive-word','','',0,1,0,'aigc_llm','app','aigc_llm_sensitive_word',0,1778000000,1778000000),
(9152,0,9146,'C','用量统计','',0,'app.aigc_llm.admin/stat','stat','apps/aigc_llm/stat','','',0,1,0,'aigc_llm','app','aigc_llm_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9153,0,9104,'C','基础配置','',50,'app.aigc_image.config/detail','config','apps/aigc_image/config','','',0,1,0,'aigc_image','app','aigc_image_config',0,1778000000,1778000000),
(9154,0,9113,'C','基础配置','',50,'app.aigc_video.config/detail','config','apps/aigc_video/config','','',0,1,0,'aigc_video','app','aigc_video_config',0,1778000000,1778000000),
(9155,0,9122,'C','基础配置','',50,'app.aigc_digital_human.config/detail','config','apps/aigc_digital_human/config','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_config',0,1778000000,1778000000),
(9156,0,9136,'C','基础配置','',50,'app.aigc_canvas.config/detail','config','apps/aigc_canvas/config','','',0,1,0,'aigc_canvas','app','aigc_canvas_config',0,1778000000,1778000000);

-- Migration snapshot: aigc_video/migrations/zz_20260521_happy_horse_channel.sql

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'happy_horse','Happy Horse','happyhorse','happyhorse-1.0-t2v',9,'{"submit_path":"/api/v1/apps/happy_horse/submit","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"resolution":"720P"}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'happy_horse','720p_3','720P · 3秒','16:9',1280,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"16:9"}',1,1200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','9:16',720,1280,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"9:16"}',1,1190,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','1:1',720,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"1:1"}',1,1180,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','4:3',960,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"4:3"}',1,1170,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','3:4',720,960,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"3:4"}',1,1160,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','16:9',1280,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"16:9"}',1,1150,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','9:16',720,1280,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"9:16"}',1,1140,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','1:1',720,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"1:1"}',1,1130,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','4:3',960,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"4:3"}',1,1120,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','3:4',720,960,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"3:4"}',1,1110,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','16:9',1280,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"16:9"}',1,1100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','9:16',720,1280,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"9:16"}',1,1090,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','1:1',720,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"1:1"}',1,1080,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','4:3',960,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"4:3"}',1,1070,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','3:4',720,960,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"3:4"}',1,1060,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','16:9',1280,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"16:9"}',1,1050,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','9:16',720,1280,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"9:16"}',1,1040,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','1:1',720,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"1:1"}',1,1030,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','4:3',960,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"4:3"}',1,1020,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','3:4',720,960,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"3:4"}',1,1010,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','16:9',1920,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','9:16',1080,1920,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','1:1',1080,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','4:3',1440,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"4:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','3:4',1080,1440,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"3:4"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','16:9',1920,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','9:16',1080,1920,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','1:1',1080,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','4:3',1440,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"4:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','3:4',1080,1440,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"3:4"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','16:9',1920,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','9:16',1080,1920,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','1:1',1080,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','4:3',1440,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"4:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','3:4',1080,1440,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"3:4"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','16:9',1920,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','9:16',1080,1920,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','1:1',1080,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','4:3',1440,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"4:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','3:4',1080,1440,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"3:4"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_video/migrations/zz_20260525_xhadmin_video_apps.sql

SET @aigc_video_task_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
);

SET @aigc_video_sql = (
  SELECT IF(
    @aigc_video_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_aigc_video_task` ADD COLUMN `reference_assets` text COMMENT ''参考素材'' AFTER `reference_images`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'reference_assets'
);
PREPARE aigc_video_stmt FROM @aigc_video_sql;
EXECUTE aigc_video_stmt;
DEALLOCATE PREPARE aigc_video_stmt;

SET @aigc_video_duration_sql = (
  SELECT IF(
    @aigc_video_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_aigc_video_task` ADD COLUMN `duration` int unsigned NOT NULL DEFAULT 0 COMMENT ''生成时长秒数'' AFTER `ratio`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'duration'
);
PREPARE aigc_video_duration_stmt FROM @aigc_video_duration_sql;
EXECUTE aigc_video_duration_stmt;
DEALLOCATE PREPARE aigc_video_duration_stmt;

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'wan','Wan 2.7','xhadmin','wan2.7',4,'{"app_code":"wan","submit_path":"/api/v1/apps/wan/create","task_path":"/api/v1/apps/wan/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[2,3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[2,3,4,5,6,7,8,9,10],"supported_asset_types":["image","video","audio"],"max_reference_images":4,"max_reference_videos":1,"max_reference_audios":1,"max_reference_assets":6}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','Seedance 2.0','xhadmin','seedance-2-text-2-video',9,'{"app_code":"seedance","submit_path":"/api/v1/apps/seedance/create","task_path":"/api/v1/tasks/{task_id}","asset_group_path":"/api/v1/apps/seedance/createGroup","asset_create_path":"/api/v1/apps/seedance/createAsset","project_name":"default","group_type":"AIGC","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','Omni-Flash-Ext','xhadmin','omni-flash-ext',3,'{"app_code":"omni_flash_ext","submit_path":"/api/v1/apps/omni_flash_ext/create","task_path":"/api/v1/tasks/{task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,6,8,10],"supported_asset_types":["image"],"max_reference_images":3,"max_reference_assets":3}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'wan','720p','720P','16:9',1280,720,47.8100,47.8100,'{"resolution":"720p","size":"16:9"}',1,1300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p','720P','9:16',720,1280,47.8100,47.8100,'{"resolution":"720p","size":"9:16"}',1,1290,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p','720P','1:1',720,720,47.8100,47.8100,'{"resolution":"720p","size":"1:1"}',1,1280,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p','720P','4:3',960,720,47.8100,47.8100,'{"resolution":"720p","size":"4:3"}',1,1270,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p','720P','3:4',720,960,47.8100,47.8100,'{"resolution":"720p","size":"3:4"}',1,1260,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p','1080P','16:9',1920,1080,78.9100,78.9100,'{"resolution":"1080p","size":"16:9"}',1,1250,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p','1080P','9:16',1080,1920,78.9100,78.9100,'{"resolution":"1080p","size":"9:16"}',1,1240,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p','1080P','1:1',1080,1080,78.9100,78.9100,'{"resolution":"1080p","size":"1:1"}',1,1230,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p','1080P','4:3',1440,1080,78.9100,78.9100,'{"resolution":"1080p","size":"4:3"}',1,1220,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p','1080P','3:4',1080,1440,78.9100,78.9100,'{"resolution":"1080p","size":"3:4"}',1,1210,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"16:9"}',1,1230,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"9:16"}',1,1220,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"1:1"}',1,1210,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','adaptive',0,0,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"adaptive"}',1,1200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','1080p_5','1080P · 5秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":5,"ratio":"16:9"}',1,1190,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','1080p_5','1080P · 5秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":5,"ratio":"9:16"}',1,1180,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"ratio":"16:9"}',1,1170,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"ratio":"9:16"}',1,1160,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"16:9"}',1,1150,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"9:16"}',1,1140,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"1:1"}',1,1130,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_4','1080P · 4秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":4,"aspect_ratio":"16:9"}',1,1120,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_4','1080P · 4秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":4,"aspect_ratio":"9:16"}',1,1110,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"16:9"}',1,1100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"9:16"}',1,1090,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"1:1"}',1,1080,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"16:9"}',1,1070,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"9:16"}',1,1060,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"16:9"}',1,1050,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"9:16"}',1,1040,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"1:1"}',1,1030,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_8','1080P · 8秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":8,"aspect_ratio":"16:9"}',1,1020,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_8','1080P · 8秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":8,"aspect_ratio":"9:16"}',1,1010,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_10','1080P · 10秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":10,"aspect_ratio":"16:9"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_10','1080P · 10秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":10,"aspect_ratio":"9:16"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_video/migrations/zz_20260530_aigc_video_duration_spec_sync.sql

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY(6, 10, 15, 20, 25, 30)
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 5, 10, 15)
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq', 'happy_horse', 'wan', 'seedance', 'omni_flash_ext');

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'seedance',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"ratio":"', template.`ratio`, '"}'),
    1,
    1000 - duration.`duration`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT DISTINCT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`provider_params_json`, '$.resolution')), ''), '720p') AS `resolution`,
        `ratio`,
        `width`,
        `height`
    FROM `la_aigc_video_channel_spec`
    WHERE `tenant_id` = 0 AND `channel_code` = 'seedance'
) AS template
CROSS JOIN (
    SELECT 3 AS `duration` UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
    UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
    UNION ALL SELECT 15
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = VALUES(`quality_label`),
    `provider_params_json` = VALUES(`provider_params_json`),
    `update_time` = VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'omni_flash_ext',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"aspect_ratio":"', template.`ratio`, '"}'),
    1,
    1000 - duration.`duration`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT DISTINCT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`provider_params_json`, '$.resolution')), ''), '720p') AS `resolution`,
        `ratio`,
        `width`,
        `height`
    FROM `la_aigc_video_channel_spec`
    WHERE `tenant_id` = 0 AND `channel_code` = 'omni_flash_ext'
) AS template
CROSS JOIN (
    SELECT 4 AS `duration` UNION ALL SELECT 6 UNION ALL SELECT 8 UNION ALL SELECT 10
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = VALUES(`quality_label`),
    `provider_params_json` = VALUES(`provider_params_json`),
    `update_time` = VALUES(`update_time`);

UPDATE `la_aigc_video_channel_spec`
SET `upstream_unit_cost` = CASE CAST(`quality` AS UNSIGNED)
        WHEN 6 THEN 30.00
        WHEN 10 THEN 50.00
        WHEN 15 THEN 75.00
        WHEN 20 THEN 100.00
        WHEN 25 THEN 125.00
        WHEN 30 THEN 150.00
        ELSE `upstream_unit_cost`
    END,
    `upstream_cost_text` = CASE CAST(`quality` AS UNSIGNED)
        WHEN 6 THEN 'Grok Video 上游 720p 6秒固定价 / 次'
        WHEN 10 THEN 'Grok Video 上游 720p 10秒固定价 / 次'
        WHEN 15 THEN 'Grok Video 上游 720p 15秒固定价 / 次'
        WHEN 20 THEN 'Grok Video 上游 720p 20秒固定价 / 次'
        WHEN 25 THEN 'Grok Video 上游 720p 25秒固定价 / 次'
        WHEN 30 THEN 'Grok Video 上游 720p 30秒固定价 / 次'
        ELSE `upstream_cost_text`
    END,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `channel_code` = 'grok_video_xaiq'
  AND CAST(`quality` AS UNSIGNED) IN (6, 10, 15, 20, 25, 30)
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0);

UPDATE `la_aigc_video_channel_spec`
SET `upstream_cost_text` = '请在规格价格页点击查询上游价格后同步',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0)
  AND (`upstream_cost_text` IS NULL OR `upstream_cost_text` = '');

UPDATE `la_aigc_image_channel_spec`
SET `upstream_cost_text` = '请在规格价格页点击查询上游价格后同步',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0)
  AND (`upstream_cost_text` IS NULL OR `upstream_cost_text` = '');

-- Migration snapshot: aigc_video/migrations/zz_20260609_video_seedance_happyhorse_points_rule_repair.sql

SET @video_spec_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec');

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_video_channel_spec` ADD COLUMN `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''上游成本单价'' AFTER `height`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'upstream_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''上游成本单价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'upstream_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `platform_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台成本单价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'platform_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `tenant_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''租户用户售价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'tenant_unit_price');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options', JSON_ARRAY(4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), '$.quantity_options', JSON_ARRAY(1), '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio'), '$.pricing_api_code', 'create', '$.api_code', 'create'),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'seedance';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), '$.videoedit_duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15), '$.quantity_options', JSON_ARRAY(1), '$.supported_asset_types', JSON_ARRAY('image', 'video'), '$.pricing_api_code', 'submit', '$.api_code', 'submit'),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'happy_horse';

UPDATE `la_aigc_video_channel_spec` AS s
JOIN (
    SELECT x.`id`,
           CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.duration')) AS UNSIGNED) AS `duration`,
           CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.resolution')))
               WHEN '1080P' THEN 0.0560
               ELSE 0.0280
           END AS `second_rate`,
           UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.resolution'))) AS `resolution`
    FROM `la_aigc_video_channel_spec` AS x
    WHERE x.`channel_code` = 'happy_horse'
) AS p ON p.`id` = s.`id`
SET s.`upstream_unit_cost` = p.`second_rate`,
    s.`platform_unit_cost` = CASE
        WHEN s.`tenant_id` = 0 THEN p.`second_rate`
        WHEN p.`duration` > 0 AND s.`platform_unit_cost` > p.`second_rate` * 1.5 THEN ROUND(s.`platform_unit_cost` / p.`duration`, 4)
        WHEN s.`platform_unit_cost` <= 0 THEN p.`second_rate`
        ELSE s.`platform_unit_cost`
    END,
    s.`tenant_unit_price` = CASE
        WHEN s.`tenant_id` = 0 THEN p.`second_rate`
        WHEN p.`duration` > 0 AND s.`tenant_unit_price` > p.`second_rate` * 1.5 THEN ROUND(s.`tenant_unit_price` / p.`duration`, 4)
        WHEN s.`tenant_unit_price` <= 0 THEN p.`second_rate`
        ELSE s.`tenant_unit_price`
    END,
    s.`upstream_cost_text` = CONCAT(COALESCE(NULLIF(p.`resolution`, ''), '720P'), ' 上游秒单价，点 / 秒'),
    s.`update_time` = UNIX_TIMESTAMP();

-- Migration snapshot: aigc_video/migrations/zz_20260609_happyhorse_second_pricing_default_repair.sql

UPDATE `la_aigc_video_channel`
SET `sort` = CASE WHEN `tenant_id` = 0 THEN 410 ELSE GREATEST(`sort`, 410) END,
    `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'happy_horse',
        '$.pricing_api_code', 'submit',
        '$.api_code', 'submit',
        '$.submit_path', '/api/v1/apps/happy_horse/submit',
        '$.query_path', '/api/v1/apps/happy_horse/query',
        '$.duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.videoedit_duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video'),
        '$.max_reference_images', 9,
        '$.max_reference_videos', 1,
        '$.max_reference_assets', 10
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'happy_horse';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'happy_horse',LOWER(t.`resolution`),t.`resolution`,t.`ratio`,t.`width`,t.`height`,t.`second_rate`,t.`second_rate`,t.`second_rate`,CONCAT(t.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', t.`resolution`, '","ratio":"', t.`ratio`, '"}'),1,1500 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720P' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0.0280 AS `second_rate`, 0 AS `sort_offset` UNION ALL
    SELECT '720P', '9:16', 720, 1280, 0.0280, 10 UNION ALL
    SELECT '720P', '1:1', 720, 720, 0.0280, 20 UNION ALL
    SELECT '720P', '4:3', 960, 720, 0.0280, 30 UNION ALL
    SELECT '720P', '3:4', 720, 960, 0.0280, 40 UNION ALL
    SELECT '1080P', '16:9', 1920, 1080, 0.0560, 200 UNION ALL
    SELECT '1080P', '9:16', 1080, 1920, 0.0560, 210 UNION ALL
    SELECT '1080P', '1:1', 1080, 1080, 0.0560, 220 UNION ALL
    SELECT '1080P', '4:3', 1440, 1080, 0.0560, 230 UNION ALL
    SELECT '1080P', '3:4', 1080, 1440, 0.0560, 240
) AS t
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `upstream_unit_cost`=VALUES(`upstream_unit_cost`),
    `platform_unit_cost`=VALUES(`platform_unit_cost`),
    `tenant_unit_price`=VALUES(`tenant_unit_price`),
    `upstream_cost_text`=VALUES(`upstream_cost_text`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=1,
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `channel_code` = 'happy_horse'
  AND (`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL);

-- Migration snapshot: aigc_video/migrations/zz_20260609_wan_second_pricing_repair.sql

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'wan',
        '$.pricing_api_code', 'create',
        '$.api_code', 'create',
        '$.duration_options', JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.videoedit_duration_options', JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio')
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'wan';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'wan',LOWER(t.`resolution`),t.`resolution`,t.`ratio`,t.`width`,t.`height`,
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`upstream_unit_cost`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`platform_unit_cost`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`tenant_unit_price`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       CONCAT(t.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', LOWER(t.`resolution`), '","size":"', t.`ratio`, '"}'),1,1600 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720P' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 47.8100 AS `second_rate`, 0 AS `sort_offset` UNION ALL
    SELECT '720P', '9:16', 720, 1280, 47.8100, 10 UNION ALL
    SELECT '720P', '1:1', 720, 720, 47.8100, 20 UNION ALL
    SELECT '720P', '4:3', 960, 720, 47.8100, 30 UNION ALL
    SELECT '720P', '3:4', 720, 960, 47.8100, 40 UNION ALL
    SELECT '1080P', '16:9', 1920, 1080, 78.9100, 200 UNION ALL
    SELECT '1080P', '9:16', 1080, 1920, 78.9100, 210 UNION ALL
    SELECT '1080P', '1:1', 1080, 1080, 78.9100, 220 UNION ALL
    SELECT '1080P', '4:3', 1440, 1080, 78.9100, 230 UNION ALL
    SELECT '1080P', '3:4', 1080, 1440, 78.9100, 240
) AS t
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `upstream_unit_cost`=VALUES(`upstream_unit_cost`),
    `platform_unit_cost`=VALUES(`platform_unit_cost`),
    `tenant_unit_price`=VALUES(`tenant_unit_price`),
    `upstream_cost_text`=VALUES(`upstream_cost_text`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=1,
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `channel_code` = 'wan'
  AND (`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL);

-- Migration snapshot: aigc_hairstyle/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_hairstyle_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_operation` varchar(30) NOT NULL DEFAULT 'hair_style_color',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI换发型配置';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_hairstyle','AI换发型','resource/image/common/menu_generator.png','面向人物发型和发色调整的 AI 图片创作应用，复用 AIGC 生图通道完成生成。','aigc','','tenant,pc',0,0,1,860,'1.0.1','installed','allow',1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_hairstyle','1.0.1','>=1.0.0','local','{"code":"aigc_hairstyle","name":"AI换发型","version":"1.0.1","require_core":">=1.0.0","description":"面向人物发型和发色调整的 AI 图片创作应用，复用 AIGC 生图通道完成生成。","changelog":"1. 新增 AI 换发型应用。\n2. 支持租户配置提示词模板和示例图片。\n3. PC 端支持本地上传人物图、发型参考图并按操作类型生成。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":860,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_hairstyle","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_hairstyle_admin","name":"AI换发型","path":"/app/aigc_hairstyle","icon":"el-icon-MagicStick","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_hairstyle","name":"AI换发型","path":"/ai/tools/aigc_hairstyle","icon":"resource/image/common/menu_generator.png","sort":92,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片生成"}]}','1. 新增 AI 换发型应用。
2. 支持租户配置提示词模板和示例图片。
3. PC 端支持本地上传人物图、发型参考图并按操作类型生成。',1,1778000000)
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_hairstyle','tenant','aigc_hairstyle_admin','AI换发型','/app/aigc_hairstyle','el-icon-MagicStick',100,1,'{}',1778000000),
('aigc_hairstyle','pc','aigc_hairstyle','AI换发型','/ai/tools/aigc_hairstyle','resource/image/common/menu_generator.png',92,1,'{}',1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_hairstyle','app.aigc_hairstyle.config/detail','GET','aigc_hairstyle:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.config/setup','POST','aigc_hairstyle:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/lists','GET','aigc_hairstyle:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/detail','GET','aigc_hairstyle:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/retry','POST','aigc_hairstyle:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/delete','POST','aigc_hairstyle:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.config/detail','GET','aigc_hairstyle:config:user','user',0,0,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.generate/estimate','POST','aigc_hairstyle:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.generate/index','POST','aigc_hairstyle:generate','user',1,0,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/lists','GET','aigc_hairstyle:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.task/detail','GET','aigc_hairstyle:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_hairstyle','app.aigc_hairstyle.result/lists','GET','aigc_hairstyle:result:lists:user','user',1,0,1,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
VALUES (0,'aigc_hairstyle','1.0.1','paid','on','enabled',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9160,0,0,'M','AI换发型','el-icon-MagicStick',92,'','aigc-hairstyle','','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle',0,1778000000,1778000000),
(9161,0,9160,'C','基础配置','',20,'app.aigc_hairstyle.config/detail','config','apps/aigc_hairstyle/config','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle_config',0,1778000000,1778000000),
(9162,0,9160,'C','任务记录','',10,'app.aigc_hairstyle.task/lists','task','apps/aigc_hairstyle/task','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle_task',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`perms`=VALUES(`perms`),`paths`=VALUES(`paths`),`component`=VALUES(`component`),`app_code`=VALUES(`app_code`),`source`=VALUES(`source`),`source_menu_key`=VALUES(`source_menu_key`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_fitting/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_fitting_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_mode` varchar(30) NOT NULL DEFAULT 'single',
  `default_upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣配置';

CREATE TABLE IF NOT EXISTS `la_aigc_fitting_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `mode` varchar(30) NOT NULL DEFAULT 'single',
  `upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `model_filter` varchar(80) NOT NULL DEFAULT '',
  `clothes_filter` varchar(80) NOT NULL DEFAULT '',
  `pose_filter` varchar(80) NOT NULL DEFAULT '',
  `garment_images` text,
  `model_images` text,
  `selected_preset_ids` text,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣任务';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_fitting','AI试衣','resource/image/common/menu_generator.png','面向服装效果预览的 AI 试衣应用，复用 AIGC 生图通道并支持独立用户售价。','aigc','','tenant,pc',0,0,1,855,'1.0.1','installed','allow',1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_fitting','1.0.1','>=1.0.0','local','{"code":"aigc_fitting","name":"AI试衣","version":"1.0.1","require_core":">=1.0.0","description":"面向服装效果预览的 AI 试衣应用，复用 AIGC 生图通道并支持独立用户售价。","changelog":"1. 新增 AI 试衣应用。\n2. 支持单图、组图和自定义模特三种试衣模式。\n3. 租户后台支持配置试衣价格、提示词、示例图和任务记录。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":855,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_fitting","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_fitting_admin","name":"AI试衣","path":"/app/aigc_fitting","icon":"el-icon-Camera","sort":95,"status":1},{"terminal":"pc","entry_key":"aigc_fitting","name":"AI试衣","path":"/ai/tools/aigc_fitting","icon":"resource/image/common/menu_generator.png","sort":91,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片生成"}]}','1. 新增 AI 试衣应用。
2. 支持单图、组图和自定义模特三种试衣模式。
3. 租户后台支持配置试衣价格、提示词、示例图和任务记录。',1,1778000000)
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_fitting','tenant','aigc_fitting_admin','AI试衣','/app/aigc_fitting','el-icon-Camera',95,1,'{}',1778000000),
('aigc_fitting','pc','aigc_fitting','AI试衣','/ai/tools/aigc_fitting','resource/image/common/menu_generator.png',91,1,'{}',1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_fitting','app.aigc_fitting.config/detail','GET','aigc_fitting:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.config/setup','POST','aigc_fitting:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/lists','GET','aigc_fitting:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/detail','GET','aigc_fitting:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/retry','POST','aigc_fitting:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/delete','POST','aigc_fitting:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.config/detail','GET','aigc_fitting:config:user','user',0,0,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.generate/estimate','POST','aigc_fitting:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.generate/index','POST','aigc_fitting:generate','user',1,0,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/lists','GET','aigc_fitting:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.task/detail','GET','aigc_fitting:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_fitting','app.aigc_fitting.result/lists','GET','aigc_fitting:result:lists:user','user',1,0,1,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
VALUES (0,'aigc_fitting','1.0.1','paid','on','enabled',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9162,0,0,'M','AI试衣','el-icon-Camera',91,'','aigc-fitting','','','',0,1,0,'aigc_fitting','app','aigc_fitting',0,1778000000,1778000000),
(9163,0,9162,'C','基础配置','',0,'app.aigc_fitting.config/detail','config','apps/aigc_fitting/config','','',0,1,0,'aigc_fitting','app','aigc_fitting_config',0,1778000000,1778000000),
(9164,0,9162,'C','任务记录','',0,'app.aigc_fitting.task/lists','task','apps/aigc_fitting/task','','',0,1,0,'aigc_fitting','app','aigc_fitting_task',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`perms`=VALUES(`perms`),`paths`=VALUES(`paths`),`component`=VALUES(`component`),`app_code`=VALUES(`app_code`),`source`=VALUES(`source`),`source_menu_key`=VALUES(`source_menu_key`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_product_image/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_size_key` varchar(60) NOT NULL DEFAULT '1:1',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_scene_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图场景分类';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_scene_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL DEFAULT '',
  `image` varchar(500) NOT NULL DEFAULT '',
  `prompt` text,
  `vip` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_category` (`tenant_id`,`category_id`,`status`,`sort`),
  KEY `idx_tenant_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图场景模板';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `product_image` varchar(500) NOT NULL DEFAULT '',
  `scene_mode` varchar(30) NOT NULL DEFAULT 'template',
  `template_id` int unsigned NOT NULL DEFAULT 0,
  `custom_scene_image` varchar(500) NOT NULL DEFAULT '',
  `size_key` varchar(80) NOT NULL DEFAULT '1:1',
  `width` int unsigned NOT NULL DEFAULT 800,
  `height` int unsigned NOT NULL DEFAULT 800,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图结果';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_image','AI商品图','resource/image/common/menu_generator.png','面向电商商品图生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、场景分类和场景模板。','aigc','','tenant,pc',0,0,1,852,'1.0.0','installed','allow',1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_image','1.0.0','>=1.0.0','local','{"code":"aigc_product_image","name":"AI商品图","version":"1.0.0","require_core":">=1.0.0","description":"面向电商商品图生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、场景分类和场景模板。","changelog":"1. 新增 AI 商品图生成工具。\n2. 支持租户维护场景分类和场景模板。\n3. 支持 PC 端上传商品图、自定义场景和独立点数计费。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":852,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_product_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_product_image_admin","name":"AI商品图","path":"/app/aigc_product_image","icon":"el-icon-Picture","sort":94,"status":1},{"terminal":"pc","entry_key":"aigc_product_image","name":"AI商品图","path":"/ai/tools/aigc_product_image","icon":"resource/image/common/menu_generator.png","sort":89,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"商品图生成"}]}','1. 新增 AI 商品图生成工具。
2. 支持租户维护场景分类和场景模板。
3. 支持 PC 端上传商品图、自定义场景和独立点数计费。',1,1778000000)
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_product_image','tenant','aigc_product_image_admin','AI商品图','/app/aigc_product_image','el-icon-Picture',94,1,'{}',1778000000),
('aigc_product_image','pc','aigc_product_image','AI商品图','/ai/tools/aigc_product_image','resource/image/common/menu_generator.png',89,1,'{}',1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_image','app.aigc_product_image.config/detail','GET','aigc_product_image:config:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.config/setup','POST','aigc_product_image:config:setup','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_category/lists','GET','aigc_product_image:scene_category:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_category/save','POST','aigc_product_image:scene_category:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_category/status','POST','aigc_product_image:scene_category:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_category/delete','POST','aigc_product_image:scene_category:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/lists','GET','aigc_product_image:scene_template:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/detail','GET','aigc_product_image:scene_template:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/save','POST','aigc_product_image:scene_template:save','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/status','POST','aigc_product_image:scene_template:status','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/delete','POST','aigc_product_image:scene_template:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/lists','GET','aigc_product_image:task:lists','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/detail','GET','aigc_product_image:task:detail','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/retry','POST','aigc_product_image:task:retry','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/delete','POST','aigc_product_image:task:delete','tenant_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.tenant/stat','GET','aigc_product_image:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.tenant/lists','GET','aigc_product_image:tenant_usage','platform_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.config/dependencies','GET','aigc_product_image:dependencies:platform','platform_admin',1,1,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.config/detail','GET','aigc_product_image:config:user','user',0,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_category/lists','GET','aigc_product_image:scene_category:user','user',0,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/lists','GET','aigc_product_image:scene_template:user','user',0,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.scene_template/detail','GET','aigc_product_image:scene_template_detail:user','user',0,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.generate/estimate','POST','aigc_product_image:generate:estimate','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.generate/index','POST','aigc_product_image:generate','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/lists','GET','aigc_product_image:task:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/detail','GET','aigc_product_image:task:detail:user','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.task/delete','POST','aigc_product_image:task:delete:user','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.result/lists','GET','aigc_product_image:result:lists:user','user',1,0,1,1778000000,1778000000),
('aigc_product_image','app.aigc_product_image.result/delete','POST','aigc_product_image:result:delete:user','user',1,0,1,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
VALUES (0,'aigc_product_image','1.0.0','paid','on','enabled',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(0,'M','AI商品图','el-icon-Picture',83,'','aigc-product-image','','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform',0,1778000000,1778000000);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`id`,'C','租户用量','',0,'app.aigc_product_image.tenant/stat','tenant-usage','apps/aigc_product_image/tenant-usage','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform_tenant_usage',0,1778000000,1778000000
FROM `la_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image_platform';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`id`,'C','依赖状态','',0,'app.aigc_product_image.config/dependencies','dependencies','apps/aigc_product_image/dependencies','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform_dependency',0,1778000000,1778000000
FROM `la_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image_platform';

INSERT INTO `la_tenant_system_menu` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9165,0,0,'M','AI商品图','el-icon-Picture',90,'','aigc-product-image','','','',0,1,0,'aigc_product_image','app','aigc_product_image',0,1778000000,1778000000),
(9166,0,9165,'C','基础配置','',40,'app.aigc_product_image.config/detail','config','apps/aigc_product_image/config','','',0,1,0,'aigc_product_image','app','aigc_product_image_config',0,1778000000,1778000000),
(9167,0,9165,'C','场景分类','',30,'app.aigc_product_image.scene_category/lists','category','apps/aigc_product_image/category','','',0,1,0,'aigc_product_image','app','aigc_product_image_category',0,1778000000,1778000000),
(9168,0,9165,'C','场景模板','',20,'app.aigc_product_image.scene_template/lists','template','apps/aigc_product_image/template','','',0,1,0,'aigc_product_image','app','aigc_product_image_template',0,1778000000,1778000000),
(9169,0,9165,'C','任务记录','',10,'app.aigc_product_image.task/lists','task','apps/aigc_product_image/task','','',0,1,0,'aigc_product_image','app','aigc_product_image_task',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`perms`=VALUES(`perms`),`paths`=VALUES(`paths`),`component`=VALUES(`component`),`app_code`=VALUES(`app_code`),`source`=VALUES(`source`),`source_menu_key`=VALUES(`source_menu_key`),`update_time`=VALUES(`update_time`);

-- Migration snapshot: aigc_video/migrations/zz_20260615_seedance2_pro_default_channel.sql

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'seedance2_pro','Seedance 2.0 Pro','seedance2_pro','seedance2_pro',9,'{"app_code":"seedance2_pro","submit_path":"/api/v1/apps/seedance2_pro/create","task_path":"/api/v1/apps/seedance2_pro/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,5,6,7,8,9,10,11,12,13,14,15],"default_duration":5,"ratio_options":["adaptive","9:16","16:9","1:1","4:3","3:4","21:9"],"mode_options":["pro","fast"],"default_mode":"pro","supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,600,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE
    `name`=VALUES(`name`),
    `provider`=VALUES(`provider`),
    `model`=VALUES(`model`),
    `max_reference_images`=VALUES(`max_reference_images`),
    `config_json`=VALUES(`config_json`),
    `status`=1,
    `sort`=GREATEST(`sort`, VALUES(`sort`)),
    `update_time`=UNIX_TIMESTAMP();

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'seedance2_pro',t.`quality`,t.`quality_label`,t.`ratio`,0,0,90.0000,100.0000,100.0000,CONCAT(t.`quality_label`,'，点 / 秒'),CONCAT('{"model":"seedance2_pro","duration":0,"mode":"', t.`quality`, '"}'),1,t.`sort`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT 'pro' AS `quality`, 'Pro 模式每秒' AS `quality_label`, 'mode_pro' AS `ratio`, 2000 AS `sort` UNION ALL
    SELECT 'fast', 'Fast 模式每秒', 'mode_fast', 1990
) AS t
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `upstream_unit_cost`=VALUES(`upstream_unit_cost`),
    `platform_unit_cost`=VALUES(`platform_unit_cost`),
    `tenant_unit_price`=VALUES(`tenant_unit_price`),
    `upstream_cost_text`=VALUES(`upstream_cost_text`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=1,
    `sort`=VALUES(`sort`),
    `update_time`=UNIX_TIMESTAMP();

UPDATE `la_aigc_video_channel`
SET `status` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq','happy_horse','happyhorse','happy_horse_video','wan','seedance','omni_flash_ext');

UPDATE `la_aigc_video_channel`
SET `name` = 'Grok Video（xAIQ）',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'grok_video_xaiq';

-- Migration snapshot: aigc_style_transfer/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_style_transfer_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_size_key` varchar(60) NOT NULL DEFAULT '1:1',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片风格化配置';

CREATE TABLE IF NOT EXISTS `la_aigc_style_transfer_style_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片风格化风格分类';

CREATE TABLE IF NOT EXISTS `la_aigc_style_transfer_style_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL DEFAULT '',
  `image` varchar(500) NOT NULL DEFAULT '',
  `prompt` text,
  `vip` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_category` (`tenant_id`,`category_id`,`status`,`sort`),
  KEY `idx_tenant_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片风格化风格模板';

CREATE TABLE IF NOT EXISTS `la_aigc_style_transfer_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `style_mode` varchar(30) NOT NULL DEFAULT 'template',
  `template_id` int unsigned NOT NULL DEFAULT 0,
  `style_image` varchar(500) NOT NULL DEFAULT '',
  `size_key` varchar(80) NOT NULL DEFAULT '1:1',
  `width` int unsigned NOT NULL DEFAULT 800,
  `height` int unsigned NOT NULL DEFAULT 800,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片风格化任务';

CREATE TABLE IF NOT EXISTS `la_aigc_style_transfer_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片风格化结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_style_transfer';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_style_transfer','图片风格化','resource/image/common/menu_generator.png','面向电商风格化生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、风格分类和风格模板。','aigc','','tenant,pc',0,0,1,852,'1.0.1','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_style_transfer','1.0.1','>=1.0.0','local','{"code":"aigc_style_transfer","name":"图片风格化","version":"1.0.1","require_core":">=1.0.0","description":"面向电商风格化生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、风格分类和风格模板。","changelog":"1. 新增 AI 风格化生成工具。\n2. 支持租户维护风格分类和风格模板。\n3. 支持 PC 端上传图片、选择风格模板和独立点数计费。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":852,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_style_transfer","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_style_transfer_admin","name":"图片风格化","path":"/app/aigc_style_transfer","icon":"el-icon-Picture","sort":94,"status":1},{"terminal":"pc","entry_key":"aigc_style_transfer","name":"图片风格化","path":"/ai/tools/aigc_style_transfer","icon":"resource/image/common/menu_generator.png","sort":89,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"风格化生成"}]}','1. 新增 AI 风格化生成工具。
2. 支持租户维护风格分类和风格模板。
3. 支持 PC 端上传图片、选择风格模板和独立点数计费。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_style_transfer','tenant','aigc_style_transfer_admin','图片风格化','/app/aigc_style_transfer','el-icon-Picture',94,1,'{}',UNIX_TIMESTAMP()),
('aigc_style_transfer','pc','aigc_style_transfer','图片风格化','/ai/tools/aigc_style_transfer','resource/image/common/menu_generator.png',89,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_style_transfer';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_style_transfer','app.aigc_style_transfer.config/detail','GET','aigc_style_transfer:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.config/setup','POST','aigc_style_transfer:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_category/lists','GET','aigc_style_transfer:style_category:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_category/save','POST','aigc_style_transfer:style_category:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_category/status','POST','aigc_style_transfer:style_category:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_category/delete','POST','aigc_style_transfer:style_category:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/lists','GET','aigc_style_transfer:style_template:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/detail','GET','aigc_style_transfer:style_template:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/save','POST','aigc_style_transfer:style_template:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/status','POST','aigc_style_transfer:style_template:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/delete','POST','aigc_style_transfer:style_template:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/lists','GET','aigc_style_transfer:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/detail','GET','aigc_style_transfer:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/retry','POST','aigc_style_transfer:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/delete','POST','aigc_style_transfer:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.config/detail','GET','aigc_style_transfer:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_category/lists','GET','aigc_style_transfer:style_category:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/lists','GET','aigc_style_transfer:style_template:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.style_template/detail','GET','aigc_style_transfer:style_template_detail:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.generate/estimate','POST','aigc_style_transfer:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.generate/index','POST','aigc_style_transfer:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/lists','GET','aigc_style_transfer:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/detail','GET','aigc_style_transfer:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.task/delete','POST','aigc_style_transfer:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.result/lists','GET','aigc_style_transfer:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_style_transfer','app.aigc_style_transfer.result/delete','POST','aigc_style_transfer:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_style_transfer','1.0.1','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_style_transfer','1.0.1','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_style_transfer'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','图片风格化','el-icon-Picture',90,'','aigc-style-transfer','','','',0,1,0,'aigc_style_transfer','app','aigc_style_transfer',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_style_transfer.config/detail','config','apps/aigc_style_transfer/config','','',0,1,0,'aigc_style_transfer','app','aigc_style_transfer_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_style_transfer' AND root.`source_menu_key`='aigc_style_transfer';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','风格分类','',30,'app.aigc_style_transfer.style_category/lists','category','apps/aigc_style_transfer/category','','',0,1,0,'aigc_style_transfer','app','aigc_style_transfer_category',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_style_transfer' AND root.`source_menu_key`='aigc_style_transfer';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','风格模板','',20,'app.aigc_style_transfer.style_template/lists','template','apps/aigc_style_transfer/template','','',0,1,0,'aigc_style_transfer','app','aigc_style_transfer_template',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_style_transfer' AND root.`source_menu_key`='aigc_style_transfer';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_style_transfer.task/lists','task','apps/aigc_style_transfer/task','','',0,1,0,'aigc_style_transfer','app','aigc_style_transfer_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_style_transfer' AND root.`source_menu_key`='aigc_style_transfer';

-- Migration snapshot: aigc_photo_restore/migrations/install.sql

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复配置';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(60) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复类型';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `restore_type` varchar(60) NOT NULL DEFAULT '',
  `restore_type_name` varchar(100) NOT NULL DEFAULT '',
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_restore_type` (`tenant_id`,`restore_type`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复任务';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_photo_restore';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_photo_restore','老照片修复','resource/image/common/menu_generator.png','面向老照片修复和上色的 AI 工具，复用 AIGC 生图通道并支持独立模型规格售价。','aigc','','tenant,pc',0,0,1,851,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_photo_restore','1.0.0','>=1.0.0','local','{"code":"aigc_photo_restore","name":"老照片修复","version":"1.0.0","require_core":">=1.0.0","description":"面向老照片修复和上色的 AI 工具，复用 AIGC 生图通道并支持独立模型规格售价。","changelog":"1. 新增老照片修复工具。\n2. 支持租户配置修复类型和模型规格售价。\n3. 支持 PC 端上传老照片、选择修复类型和生成作品。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":851,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_photo_restore","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_photo_restore_admin","name":"老照片修复","path":"/app/aigc_photo_restore","icon":"el-icon-Picture","sort":93,"status":1},{"terminal":"pc","entry_key":"aigc_photo_restore","name":"老照片修复","path":"/ai/tools/aigc_photo_restore","icon":"resource/image/common/menu_generator.png","sort":88,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"照片修复生成"}]}','1. 新增老照片修复工具。
2. 支持租户配置修复类型和模型规格售价。
3. 支持 PC 端上传老照片、选择修复类型和生成作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_photo_restore','tenant','aigc_photo_restore_admin','老照片修复','/app/aigc_photo_restore','el-icon-Picture',93,1,'{}',UNIX_TIMESTAMP()),
('aigc_photo_restore','pc','aigc_photo_restore','老照片修复','/ai/tools/aigc_photo_restore','resource/image/common/menu_generator.png',88,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_photo_restore';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_photo_restore','app.aigc_photo_restore.config/detail','GET','aigc_photo_restore:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.config/setup','POST','aigc_photo_restore:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/lists','GET','aigc_photo_restore:restore_type:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/save','POST','aigc_photo_restore:restore_type:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/status','POST','aigc_photo_restore:restore_type:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.price/detail','GET','aigc_photo_restore:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.price/setup','POST','aigc_photo_restore:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/lists','GET','aigc_photo_restore:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/detail','GET','aigc_photo_restore:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/retry','POST','aigc_photo_restore:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/delete','POST','aigc_photo_restore:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.config/detail','GET','aigc_photo_restore:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/lists','GET','aigc_photo_restore:restore_type:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.generate/estimate','POST','aigc_photo_restore:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.generate/index','POST','aigc_photo_restore:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/lists','GET','aigc_photo_restore:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/detail','GET','aigc_photo_restore:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/delete','POST','aigc_photo_restore:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.result/lists','GET','aigc_photo_restore:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.result/delete','POST','aigc_photo_restore:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_photo_restore','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_photo_restore','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_photo_restore'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','老照片修复','el-icon-Picture',89,'','aigc-photo-restore','','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_photo_restore.config/detail','config','apps/aigc_photo_restore/config','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','修复类型','',30,'app.aigc_photo_restore.restore_type/lists','restore-type','apps/aigc_photo_restore/restore-type','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_type',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',20,'app.aigc_photo_restore.price/detail','price','apps/aigc_photo_restore/price','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_photo_restore.task/lists','task','apps/aigc_photo_restore/task','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `default_target_language` varchar(30) NOT NULL DEFAULT 'en',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译配置';

CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `source_language` varchar(30) NOT NULL DEFAULT 'auto',
  `source_language_label` varchar(80) NOT NULL DEFAULT '',
  `target_language` varchar(30) NOT NULL DEFAULT 'en',
  `target_language_label` varchar(80) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_language` (`tenant_id`,`source_language`,`target_language`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译任务';

CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译结果';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_image_translate','图片翻译','resource/image/common/menu_generator.png','面向商品图、海报和素材图的 AI 图片翻译工具，复用 AIGC 生图通道并支持租户独立翻译质量定价。','aigc','','tenant,pc',0,0,1,850,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_image_translate','1.0.0','>=1.0.0','local','{"code":"aigc_image_translate","name":"图片翻译","version":"1.0.0","require_core":">=1.0.0","description":"面向商品图、人物图和素材图的 AI 图片翻译工具，复用 AIGC 生图通道并支持租户独立翻译质量定价。","changelog":"1. 新增图片翻译工具。\n2. 支持租户配置翻译质量售价。\n3. 支持 PC 端上传图片生成翻译作品。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":852,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_image_translate","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_image_translate_admin","name":"图片翻译","path":"/app/aigc_image_translate","icon":"el-icon-Picture","sort":92,"status":1},{"terminal":"pc","entry_key":"aigc_image_translate","name":"图片翻译","path":"/ai/tools/aigc_image_translate","icon":"resource/image/common/menu_generator.png","sort":87,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片翻译生成"}]}','1. 新增图片翻译工具。
2. 支持租户配置翻译质量售价。
3. 支持 PC 端上传图片生成翻译作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_image_translate','tenant','aigc_image_translate_admin','图片翻译','/app/aigc_image_translate','el-icon-Picture',92,1,'{}',UNIX_TIMESTAMP()),
('aigc_image_translate','pc','aigc_image_translate','图片翻译','/ai/tools/aigc_image_translate','resource/image/common/menu_generator.png',87,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_image_translate';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_image_translate','app.aigc_image_translate.config/detail','GET','aigc_image_translate:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.config/setup','POST','aigc_image_translate:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.price/detail','GET','aigc_image_translate:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.price/setup','POST','aigc_image_translate:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/lists','GET','aigc_image_translate:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/detail','GET','aigc_image_translate:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/retry','POST','aigc_image_translate:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/delete','POST','aigc_image_translate:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.config/detail','GET','aigc_image_translate:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.generate/estimate','POST','aigc_image_translate:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.generate/index','POST','aigc_image_translate:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/lists','GET','aigc_image_translate:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/detail','GET','aigc_image_translate:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/delete','POST','aigc_image_translate:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.result/lists','GET','aigc_image_translate:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.result/delete','POST','aigc_image_translate:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_image_translate','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_image_translate','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_image_translate'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','图片翻译','el-icon-Picture',86,'','aigc-image-translate','','','',0,1,0,'aigc_image_translate','app','aigc_image_translate',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_image_translate.config/detail','config','apps/aigc_image_translate/config','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_image_translate.price/detail','price','apps/aigc_image_translate/price','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_image_translate.task/lists','task','apps/aigc_image_translate/task','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';

-- ----------------------------
-- 一键消除应用
-- ----------------------------

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_images` int unsigned NOT NULL DEFAULT 10,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除配置';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_option` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除选项';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_images` text,
  `option_codes` text,
  `option_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_batch` (`tenant_id`,`batch_no`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除任务';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_one_click_cleanup','一键消除','resource/image/common/menu_generator.png','面向商品图、素材图和内容图的 AI 一键消除工具，支持批量上传、多选消除项和租户独立单张定价。','aigc','','tenant,pc',0,0,1,849,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_frontend_entry`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_one_click_cleanup','tenant','aigc_one_click_cleanup_admin','一键消除','/app/aigc_one_click_cleanup','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','pc','aigc_one_click_cleanup','一键消除','/ai/tools/aigc_one_click_cleanup','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/detail','GET','aigc_one_click_cleanup:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/setup','POST','aigc_one_click_cleanup:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/lists','GET','aigc_one_click_cleanup:option:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/save','POST','aigc_one_click_cleanup:option:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/status','POST','aigc_one_click_cleanup:option:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/delete','POST','aigc_one_click_cleanup:option:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.price/detail','GET','aigc_one_click_cleanup:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.price/setup','POST','aigc_one_click_cleanup:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/lists','GET','aigc_one_click_cleanup:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/detail','GET','aigc_one_click_cleanup:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/retry','POST','aigc_one_click_cleanup:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/delete','POST','aigc_one_click_cleanup:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/detail','GET','aigc_one_click_cleanup:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/lists','GET','aigc_one_click_cleanup:option:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.generate/estimate','POST','aigc_one_click_cleanup:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.generate/index','POST','aigc_one_click_cleanup:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/lists','GET','aigc_one_click_cleanup:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/detail','GET','aigc_one_click_cleanup:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/delete','POST','aigc_one_click_cleanup:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.result/lists','GET','aigc_one_click_cleanup:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.result/delete','POST','aigc_one_click_cleanup:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_one_click_cleanup','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_one_click_cleanup','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_one_click_cleanup' AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','一键消除','el-icon-Picture',85,'','aigc-one-click-cleanup','','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_one_click_cleanup.config/detail','config','apps/aigc_one_click_cleanup/config','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','消除选项','',35,'app.aigc_one_click_cleanup.option/lists','option','apps/aigc_one_click_cleanup/option','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_option',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_one_click_cleanup.price/detail','price','apps/aigc_one_click_cleanup/price','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_one_click_cleanup.task/lists','task','apps/aigc_one_click_cleanup/task','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';



-- ----------------------------
-- Built-in app: 商品多角度
-- ----------------------------
CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_view` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度视角选项';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `view_codes` text,
  `view_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(1000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_batch` (`tenant_id`,`batch_no`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `view_code` varchar(80) NOT NULL DEFAULT '',
  `view_name` varchar(100) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_task_view` (`tenant_id`,`task_id`,`view_code`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度结果';


DELETE FROM `la_app` WHERE `code`='aigc_product_multi_angle';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`frontends`,`price_min`,`price_max`,`is_builtin`,`sort`,`version`,`status`,`expire_policy`,`create_time`,`update_time`)
VALUES ('aigc_product_multi_angle','商品多角度','resource/image/common/menu_generator.png','面向商品图的 AI 商品多角度工具，支持单图上传、多选视角和租户独立按视角定价。','aigc','','tenant,pc',0,0,1,848,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_app_version` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`source`,`manifest_json`,`changelog`,`create_time`)
VALUES ('aigc_product_multi_angle','1.0.0','>=1.0.0','local','{\n  "code": "aigc_product_multi_angle",\n  "name": "商品多角度",\n  "version": "1.0.0",\n  "require_core": ">=1.0.0",\n  "description": "面向商品图的 AI 商品多角度工具，支持单图上传、多选视角和租户独立按视角定价。",\n  "changelog": "1. 新增商品多角度工具。\\n2. 支持租户配置视角选项和按视角售价。\\n3. 支持 PC 端单图生成多视角作品。",\n  "icon": "resource/image/common/menu_generator.png",\n  "category": "aigc",\n  "cover": "",\n  "is_builtin": 1,\n  "expire_policy": "allow",\n  "sort": 849,\n  "frontends": ["tenant", "pc"],\n  "api_prefix": "/app/aigc_product_multi_angle",\n  "menus": "menus/tenant.json",\n  "permissions": "permissions/tenant.json",\n  "migrations": "migrations",\n  "frontend_entries": [\n    { "terminal": "tenant", "entry_key": "aigc_product_multi_angle_admin", "name": "商品多角度", "path": "/app/aigc_product_multi_angle", "icon": "el-icon-Picture", "sort": 91, "status": 1 },\n    { "terminal": "pc", "entry_key": "aigc_product_multi_angle", "name": "商品多角度", "path": "/ai/tools/aigc_product_multi_angle", "icon": "resource/image/common/menu_generator.png", "sort": 84, "status": 1 }\n  ],\n  "dependencies": [\n    { "app_code": "aigc_image", "name": "AIGC生图", "required_for": "商品多角度生成" }\n  ]\n}\n','1. 新增商品多角度工具。
2. 支持租户配置视角选项和按视角售价。
3. 支持 PC 端单图生成多视角作品。',UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_product_multi_angle','tenant','aigc_product_multi_angle_admin','商品多角度','/app/aigc_product_multi_angle','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_multi_angle','pc','aigc_product_multi_angle','商品多角度','/ai/tools/aigc_product_multi_angle','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/detail','GET','aigc_product_multi_angle:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/setup','POST','aigc_product_multi_angle:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/lists','GET','aigc_product_multi_angle:view:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/save','POST','aigc_product_multi_angle:view:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/status','POST','aigc_product_multi_angle:view:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/delete','POST','aigc_product_multi_angle:view:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.price/detail','GET','aigc_product_multi_angle:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.price/setup','POST','aigc_product_multi_angle:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/lists','GET','aigc_product_multi_angle:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/detail','GET','aigc_product_multi_angle:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/retry','POST','aigc_product_multi_angle:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/delete','POST','aigc_product_multi_angle:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/detail','GET','aigc_product_multi_angle:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/lists','GET','aigc_product_multi_angle:view:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.generate/estimate','POST','aigc_product_multi_angle:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.generate/index','POST','aigc_product_multi_angle:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/lists','GET','aigc_product_multi_angle:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/detail','GET','aigc_product_multi_angle:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/delete','POST','aigc_product_multi_angle:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.result/lists','GET','aigc_product_multi_angle:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.result/delete','POST','aigc_product_multi_angle:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_product_multi_angle','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_product_multi_angle','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_multi_angle' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','商品多角度','el-icon-Picture',84,'','aigc-product-multi-angle','','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_multi_angle.config/detail','config','apps/aigc_product_multi_angle/config','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','视角选项','',35,'app.aigc_product_multi_angle.view/lists','view','apps/aigc_product_multi_angle/view','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_view',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_product_multi_angle.price/detail','price','apps/aigc_product_multi_angle/price','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_multi_angle.task/lists','task','apps/aigc_product_multi_angle/task','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';



-- ----------------------------
-- Built-in app: 产品宣传视频
-- ----------------------------
CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `default_duration` int unsigned NOT NULL DEFAULT 0,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `price_matrix` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频类型';

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_task_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `type_code` varchar(80) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `type_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(2000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_video_task` (`video_task_id`),
  KEY `idx_type` (`tenant_id`,`type_code`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `video_task_id` int unsigned NOT NULL DEFAULT 0,
  `video_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `type_code` varchar(80) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_video_result` (`tenant_id`,`video_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频结果';

DELETE FROM `la_app` WHERE `code`='aigc_product_promo_video';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_promo_video','产品宣传视频','resource/image/common/menu_generator.png','面向电商产品传播的 AI 产品宣传视频工具，支持产品图生成视频、租户配置视频类型和按秒生成售价。','aigc','','tenant,pc',0,0,1,847,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_version` WHERE `app_code`='aigc_product_promo_video';
INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_promo_video','1.0.0','>=1.0.0','local','{\n  "code": "aigc_product_promo_video",\n  "name": "产品宣传视频",\n  "version": "1.0.0",\n  "require_core": ">=1.0.0",\n  "description": "面向电商产品传播的 AI 产品宣传视频工具，支持产品图生成视频、租户配置视频类型和按秒生成售价。",\n  "changelog": "1. 新增产品宣传视频工具。\\n2. 支持租户配置视频类型和按秒生成售价。\\n3. 支持 PC 端上传产品图生成宣传视频。",\n  "icon": "resource/image/common/menu_generator.png",\n  "category": "aigc",\n  "cover": "",\n  "is_builtin": 1,\n  "expire_policy": "allow",\n  "sort": 847,\n  "frontends": ["tenant", "pc"],\n  "api_prefix": "/app/aigc_product_promo_video",\n  "menus": "menus/tenant.json",\n  "permissions": "permissions/tenant.json",\n  "migrations": "migrations",\n  "frontend_entries": [\n    { "terminal": "tenant", "entry_key": "aigc_product_promo_video_admin", "name": "产品宣传视频", "path": "/app/aigc_product_promo_video", "icon": "el-icon-VideoCamera", "sort": 91, "status": 1 },\n    { "terminal": "pc", "entry_key": "aigc_product_promo_video", "name": "产品宣传视频", "path": "/ai/tools/aigc_product_promo_video", "icon": "resource/image/common/menu_generator.png", "sort": 83, "status": 1 }\n  ],\n  "dependencies": [\n    { "app_code": "aigc_video", "name": "AIGC生视频", "required_for": "产品宣传视频生成" },\n    { "app_code": "aigc_llm", "name": "AIGC对话", "required_for": "描述词AI帮写和优化" }\n  ]\n}\n','1. 新增产品宣传视频工具。
2. 支持租户配置视频类型和按秒生成售价。
3. 支持 PC 端上传产品图生成宣传视频。',1,UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_promo_video';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_product_promo_video','tenant','aigc_product_promo_video_admin','产品宣传视频','/app/aigc_product_promo_video','el-icon-VideoCamera',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_promo_video','pc','aigc_product_promo_video','产品宣传视频','/ai/tools/aigc_product_promo_video','resource/image/common/menu_generator.png',83,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_promo_video';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_promo_video','app.aigc_product_promo_video.config/detail','GET','aigc_product_promo_video:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.config/setup','POST','aigc_product_promo_video:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/lists','GET','aigc_product_promo_video:type:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/save','POST','aigc_product_promo_video:type:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/status','POST','aigc_product_promo_video:type:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/delete','POST','aigc_product_promo_video:type:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/lists','GET','aigc_product_promo_video:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/detail','GET','aigc_product_promo_video:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/retry','POST','aigc_product_promo_video:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/delete','POST','aigc_product_promo_video:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.config/detail','GET','aigc_product_promo_video:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/lists','GET','aigc_product_promo_video:type:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.prompt/write','POST','aigc_product_promo_video:prompt:write','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.prompt/optimize','POST','aigc_product_promo_video:prompt:optimize','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.generate/estimate','POST','aigc_product_promo_video:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.generate/index','POST','aigc_product_promo_video:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/lists','GET','aigc_product_promo_video:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/detail','GET','aigc_product_promo_video:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/delete','POST','aigc_product_promo_video:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.result/lists','GET','aigc_product_promo_video:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.result/delete','POST','aigc_product_promo_video:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_product_promo_video','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_product_promo_video','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_promo_video' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','产品宣传视频','el-icon-VideoCamera',83,'','aigc-product-promo-video','','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_promo_video.config/detail','config','apps/aigc_product_promo_video/config','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','视频类型','',35,'app.aigc_product_promo_video.type/lists','type','apps/aigc_product_promo_video/type','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_type',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_promo_video.task/lists','task','apps/aigc_product_promo_video/task','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';


-- ----------------------------
-- Built-in app: 无缝扩图
-- ----------------------------
CREATE TABLE IF NOT EXISTS `la_aigc_outpaint_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='无缝扩图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_outpaint_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='无缝扩图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_outpaint_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='无缝扩图结果';

DELETE FROM `la_app` WHERE `code`='aigc_outpaint';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_outpaint','无缝扩图','resource/image/common/menu_generator.png','面向商品图、人物图和素材图的 AI 无缝扩图工具，复用 AIGC 生图通道并支持租户独立扩图比例定价。','aigc','','tenant,pc',0,0,1,852,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_version` WHERE `app_code`='aigc_outpaint';
INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_outpaint','1.0.0','>=1.0.0','local','{\n  "code": "aigc_outpaint",\n  "name": "无缝扩图",\n  "version": "1.0.0",\n  "require_core": ">=1.0.0",\n  "description": "面向商品图、人物图和素材图的 AI 无缝扩图工具，复用 AIGC 生图通道并支持租户独立扩图比例定价。",\n  "changelog": "1. 新增无缝扩图工具。\\n2. 支持租户配置扩图比例售价。\\n3. 支持 PC 端上传图片生成扩图作品。",\n  "icon": "resource/image/common/menu_generator.png",\n  "category": "aigc",\n  "cover": "",\n  "is_builtin": 1,\n  "expire_policy": "allow",\n  "sort": 852,\n  "frontends": [\n    "tenant",\n    "pc"\n  ],\n  "api_prefix": "/app/aigc_outpaint",\n  "menus": "menus/tenant.json",\n  "permissions": "permissions/tenant.json",\n  "migrations": "migrations",\n  "frontend_entries": [\n    {\n      "terminal": "tenant",\n      "entry_key": "aigc_outpaint_admin",\n      "name": "无缝扩图",\n      "path": "/app/aigc_outpaint",\n      "icon": "el-icon-Picture",\n      "sort": 92,\n      "status": 1\n    },\n    {\n      "terminal": "pc",\n      "entry_key": "aigc_outpaint",\n      "name": "无缝扩图",\n      "path": "/ai/tools/aigc_outpaint",\n      "icon": "resource/image/common/menu_generator.png",\n      "sort": 87,\n      "status": 1\n    }\n  ],\n  "dependencies": [\n    {\n      "app_code": "aigc_image",\n      "name": "AIGC生图",\n      "required_for": "无缝扩图生成"\n    }\n  ]\n}\n','1. 新增无缝扩图工具。
2. 支持租户配置扩图比例售价。
3. 支持 PC 端上传图片生成扩图作品。',1,UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_outpaint';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_outpaint','tenant','aigc_outpaint_admin','无缝扩图','/app/aigc_outpaint','el-icon-Picture',92,1,'{}',UNIX_TIMESTAMP()),
('aigc_outpaint','pc','aigc_outpaint','无缝扩图','/ai/tools/aigc_outpaint','resource/image/common/menu_generator.png',87,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_outpaint';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_outpaint','app.aigc_outpaint.config/detail','GET','aigc_outpaint:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.config/setup','POST','aigc_outpaint:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.price/detail','GET','aigc_outpaint:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.price/setup','POST','aigc_outpaint:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/lists','GET','aigc_outpaint:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/detail','GET','aigc_outpaint:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/retry','POST','aigc_outpaint:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/delete','POST','aigc_outpaint:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.config/detail','GET','aigc_outpaint:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.generate/estimate','POST','aigc_outpaint:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.generate/index','POST','aigc_outpaint:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/lists','GET','aigc_outpaint:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/detail','GET','aigc_outpaint:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.task/delete','POST','aigc_outpaint:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.result/lists','GET','aigc_outpaint:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_outpaint','app.aigc_outpaint.result/delete','POST','aigc_outpaint:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_outpaint','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_outpaint','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_outpaint' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','无缝扩图','el-icon-Picture',82,'','aigc-outpaint','','','',0,1,0,'aigc_outpaint','app','aigc_outpaint',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_outpaint.config/detail','config','apps/aigc_outpaint/config','','',0,1,0,'aigc_outpaint','app','aigc_outpaint_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_outpaint' AND root.`source_menu_key`='aigc_outpaint';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_outpaint.price/detail','price','apps/aigc_outpaint/price','','',0,1,0,'aigc_outpaint','app','aigc_outpaint_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_outpaint' AND root.`source_menu_key`='aigc_outpaint';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_outpaint.task/lists','task','apps/aigc_outpaint/task','','',0,1,0,'aigc_outpaint','app','aigc_outpaint_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_outpaint' AND root.`source_menu_key`='aigc_outpaint';


-- Built-in app: AI商品套图
CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_module` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图模块选项';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `product_images` text,
  `platform` varchar(80) NOT NULL DEFAULT '',
  `country` varchar(80) NOT NULL DEFAULT '',
  `language` varchar(80) NOT NULL DEFAULT '',
  `module_codes` text,
  `module_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(1000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_batch` (`tenant_id`,`batch_no`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `module_code` varchar(80) NOT NULL DEFAULT '',
  `module_name` varchar(100) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_task_module` (`tenant_id`,`task_id`,`module_code`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图结果';

DELETE FROM `la_app` WHERE `code`='aigc_product_suite';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_suite','AI商品套图','resource/image/common/menu_generator.png','面向商品图的 AI 商品套图工具，支持最多3张商品图、多选模块和租户独立按模块定价。','aigc','','tenant,pc',0,0,1,849,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_suite';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_product_suite','tenant','aigc_product_suite_admin','AI商品套图','/app/aigc_product_suite','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_suite','pc','aigc_product_suite','AI商品套图','/ai/tools/aigc_product_suite','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_suite';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_suite','app.aigc_product_suite.config/detail','GET','aigc_product_suite:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.config/setup','POST','aigc_product_suite:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/lists','GET','aigc_product_suite:module:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/save','POST','aigc_product_suite:module:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/status','POST','aigc_product_suite:module:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/delete','POST','aigc_product_suite:module:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.price/detail','GET','aigc_product_suite:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.price/setup','POST','aigc_product_suite:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/lists','GET','aigc_product_suite:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/detail','GET','aigc_product_suite:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/retry','POST','aigc_product_suite:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/delete','POST','aigc_product_suite:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.config/detail','GET','aigc_product_suite:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/lists','GET','aigc_product_suite:module:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.prompt/optimize','POST','aigc_product_suite:prompt:optimize','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.generate/estimate','POST','aigc_product_suite:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.generate/index','POST','aigc_product_suite:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/lists','GET','aigc_product_suite:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/detail','GET','aigc_product_suite:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/delete','POST','aigc_product_suite:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.result/lists','GET','aigc_product_suite:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.result/delete','POST','aigc_product_suite:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_suite','1.0.0','>=1.0.0','local','{"code":"aigc_product_suite","name":"AI商品套图","version":"1.0.0","require_core":">=1.0.0","description":"面向商品图的 AI 商品套图工具，支持最多3张商品图、多选模块和租户独立按模块定价。","changelog":"1. 新增AI商品套图工具。\n2. 支持租户配置模块选项和按模块售价。\n3. 支持 PC 端单图生成多模块作品。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":849,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_product_suite","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_product_suite_admin","name":"AI商品套图","path":"/app/aigc_product_suite","icon":"el-icon-Picture","sort":91,"status":1},{"terminal":"pc","entry_key":"aigc_product_suite","name":"AI商品套图","path":"/ai/tools/aigc_product_suite","icon":"resource/image/common/menu_generator.png","sort":84,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"AI商品套图生成"},{"app_code":"aigc_llm","name":"AIGC对话","required_for":"核心卖点AI优化"}]}','1. 新增AI商品套图工具。
2. 支持租户配置模块选项和按模块售价。
3. 支持 PC 端单图生成多模块作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_product_suite','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_product_suite','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_suite' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','AI商品套图','el-icon-Picture',84,'','aigc-product-suite','','','',0,1,0,'aigc_product_suite','app','aigc_product_suite',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_suite.config/detail','config','apps/aigc_product_suite/config','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','模块选项','',35,'app.aigc_product_suite.module/lists','module','apps/aigc_product_suite/module','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_module',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_product_suite.price/detail','price','apps/aigc_product_suite/price','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_suite.task/lists','task','apps/aigc_product_suite/task','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';

SET
    FOREIGN_KEY_CHECKS = 1;


-- Built-in app: aigc_local_redraw
CREATE TABLE IF NOT EXISTS `la_aigc_local_redraw_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='局部重绘配置';

CREATE TABLE IF NOT EXISTS `la_aigc_local_redraw_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `mask_image` varchar(500) NOT NULL DEFAULT '',
  `user_prompt` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='局部重绘任务';

CREATE TABLE IF NOT EXISTS `la_aigc_local_redraw_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='局部重绘结果';

SET @aigc_local_redraw_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_task' AND COLUMN_NAME = 'provider_params_json') = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `provider_params_json` text COMMENT ''供应商透传参数'' AFTER `reference_images`', 'SELECT 1');
PREPARE aigc_local_redraw_stmt FROM @aigc_local_redraw_sql;
EXECUTE aigc_local_redraw_stmt;
DEALLOCATE PREPARE aigc_local_redraw_stmt;

DELETE FROM `la_app` WHERE `code`='aigc_local_redraw';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_local_redraw','局部重绘','resource/image/common/menu_generator.png','面向商品图、人物图和素材图的 AI 局部重绘工具，支持上传原图、绘制蒙版并按租户后台单价生成重绘作品。','aigc','','tenant,pc',0,0,1,853,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`description`=VALUES(`description`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_local_redraw','1.0.0','>=1.0.0','local','{"code":"aigc_local_redraw","name":"局部重绘","version":"1.0.0","require_core":">=1.0.0"}','1. 新增局部重绘工具。\n2. 支持租户配置默认生图模型规格和单次售价。\n3. 支持 PC 端上传原图、绘制蒙版并生成局部重绘作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_local_redraw';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`) VALUES
('aigc_local_redraw','tenant','aigc_local_redraw_admin','局部重绘','/app/aigc_local_redraw','el-icon-Picture',93,1,'{}',UNIX_TIMESTAMP()),
('aigc_local_redraw','pc','aigc_local_redraw','局部重绘','/ai/tools/aigc_local_redraw','resource/image/common/menu_generator.png',88,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_local_redraw';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES
('aigc_local_redraw','app.aigc_local_redraw.config/detail','GET','aigc_local_redraw:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.config/setup','POST','aigc_local_redraw:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/lists','GET','aigc_local_redraw:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/detail','GET','aigc_local_redraw:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/retry','POST','aigc_local_redraw:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/delete','POST','aigc_local_redraw:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.config/detail','GET','aigc_local_redraw:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.generate/estimate','POST','aigc_local_redraw:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.generate/index','POST','aigc_local_redraw:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/lists','GET','aigc_local_redraw:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/detail','GET','aigc_local_redraw:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.task/delete','POST','aigc_local_redraw:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.result/lists','GET','aigc_local_redraw:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_local_redraw','app.aigc_local_redraw.result/delete','POST','aigc_local_redraw:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_local_redraw','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_local_redraw','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_local_redraw' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','局部重绘','el-icon-Picture',81,'','aigc-local-redraw','','','',0,1,0,'aigc_local_redraw','app','aigc_local_redraw',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_local_redraw.config/detail','config','apps/aigc_local_redraw/config','','',0,1,0,'aigc_local_redraw','app','aigc_local_redraw_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_local_redraw' AND root.`source_menu_key`='aigc_local_redraw';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_local_redraw.task/lists','task','apps/aigc_local_redraw/task','','',0,1,0,'aigc_local_redraw','app','aigc_local_redraw_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_local_redraw' AND root.`source_menu_key`='aigc_local_redraw';
