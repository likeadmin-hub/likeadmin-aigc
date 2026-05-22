/*

 Target Server Type    : MySQL
 Target Server Version : 50729 (5.7.29)
 File Encoding         : 65001

 Date: 01/10/2024 14:18:29
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Records of la_article
-- ----------------------------
BEGIN;
INSERT INTO `la_article_{tenantSn}`
VALUES (1,{tenantId} , 3, '让生活更精致！五款居家好物推荐，实用性超高', '##好物推荐🔥',
        '随着当代生活节奏的忙碌，很多人在闲暇之余都想好好的享受生活。随着科技的发展，也出现了越来越多可以帮助我们提升幸福感，让生活变得更精致的产品，下面周周就给大家盘点五款居家必备的好物，都是实用性很高的产品，周周可以保证大家买了肯定会喜欢。',
        'resource/image/tenantapi/default/article01.png', '红花',
        '<p>拥有一台投影仪，闲暇时可以在家里直接看影院级别的大片，光是想想都觉得超级爽。市面上很多投影仪大几千，其实周周觉得没必要，选泰捷这款一千多的足够了，性价比非常高。</p><p>泰捷的专业度很高，在电视TV领域研发已经十年，有诸多专利和技术创新，荣获国内外多项技术奖项，拿下了腾讯创新工场投资，打造的泰捷视频TV端和泰捷电视盒子都获得了极高评价。</p><p>这款投影仪的分辨率在3000元内无敌，做到了真1080P高分辨率，也就是跟市场售价三千DLP投影仪一样的分辨率，真正做到了分毫毕现，像桌布的花纹、天空的云彩等，这些细节都清晰可见。</p><p>亮度方面，泰捷达到了850ANSI流明，同价位一般是200ANSI。这是因为泰捷为了提升亮度和LCD技术透射率低的问题，首创高功率LED灯源，让其亮度做到同价位最好。专业媒体也进行了多次对比，效果与3000元价位投影仪相当。</p><p>操作系统周周也很喜欢，完全不卡。泰捷作为资深音视频品牌，在系统优化方面有十年的研发经验，打造出的“零极”系统是业内公认效率最高、速度最快的系统，用户也评价它流畅度能一台顶三台，而且为了解决行业广告多这一痛点，系统内不植入任何广告。</p>',
        1, 2, 1, 0, 1663317759, 1727070911, NULL),
       (2, {tenantId}, 2, '埋葬UI设计师的坟墓不是内卷，而是免费模式', '',
        '本文从另外一个角度，聊聊作者对UI设计师职业发展前景的担忧，欢迎从事UI设计的同学来参与讨论，会有赠书哦',
        'resource/image/tenantapi/default/article02.jpeg', '小明',
        '<p><br></p><p style=\"text-align: justify;\">一个职业，卷，根本就没什么大不了的，尤其是成熟且收入高的职业，不卷才不符合事物发展的规律。何况 UI 设计师的人力市场到今天也和 5 年前一样，还是停留在大型菜鸡互啄的场面。远不能和医疗、证券、教师或者演艺练习生相提并论。</p><p style=\"text-align: justify;\">真正会让我对UI设计师发展前景觉得悲观的事情就只有一件 —— 国内的互联网产品免费机制。这也是一个我一直以来想讨论的话题，就在这次写一写。</p><p style=\"text-align: justify;\">国内互联网市场的发展，是一部浩瀚的 “免费经济” 发展史。虽然今天免费已经是深入国内民众骨髓的认知，但最早的中文互联网也是需要付费的，网游也都是要花钱的。</p><p style=\"text-align: justify;\">只是自有国情在此，付费确实阻碍了互联网行业的扩张和普及，一批创业家就开始通过免费的模式为用户提供服务，从而扩大了自己的产品覆盖面和普及程度。</p><p style=\"text-align: justify;\">印象最深的就是免费急先锋周鸿祎，和现在鲜少出现在公众视野不同，一零年前他是当之无愧的互联网教主，因为他开发出了符合中国国情的互联网产品 “打法”，让 360 的发展如日中天。</p><p style=\"text-align: justify;\">就是他在自传中提到：</p><p style=\"text-align: justify;\">只要是在互联网上每个人都需要的服务，我们就认为它是基础服务，基础服务一定是免费的，这样的话不会形成价值歧视。就是说，只要这种服务是每个人都一定要用的，我一定免费提供，而且是无条件免费。增值服务不是所有人都需要的，这个比例可能会相当低，它只是百分之几甚至更少比例的人需要，所以这种服务一定要收费……</p><p style=\"text-align: justify;\">这就是互联网的游戏规则，它决定了要想建立一个有效的商业模式，就一定要有海量的用户基数……</p>',
        2, 4, 1, 0, 1663322854, 1727071178, NULL),
       (3, {tenantId}, 1, '金山电池公布“沪广深市民绿色生活方式”调查结果', '',
        '60%以上受访者认为高质量的10分钟足以完成“自我充电”', 'resource/image/tenantapi/default/article03.png',
        '中网资讯科技',
        '<p style=\"text-align: left;\"><strong>深圳，2021年10月22日）</strong>生活在一线城市的沪广深市民一向以效率见称，工作繁忙和快节奏的生活容易缺乏充足的休息。近日，一项针对沪广深市民绿色生活方式而展开的网络问卷调查引起了大家的注意。问卷的问题设定集中于市民对休息时间的看法，以及从对循环充电电池的使用方面了解其对绿色生活方式的态度。该调查采用随机抽样的模式，并对最终收集的1,500份有效问卷进行专业分析后发现，超过60%的受访者表示，在每天的工作时段能拥有10分钟高质量的休息时间，就可以高效“自我充电”。该调查结果反映出，在快节奏时代下，人们需要高质量的休息时间，也要学会利用高效率的休息方式和工具来应对快节奏的生活，以时刻保持“满电”状态。</p><p style=\"text-align: left;\">　　<strong>60%以上受访者认为高质量的10分钟足以完成“自我充电”</strong></p><p style=\"text-align: left;\">　　这次调查超过1,500人，主要聚焦18至85岁的沪广深市民，了解他们对于休息时间的观念及使用充电电池的习惯，结果发现：</p><p style=\"text-align: left;\">　　· 90%以上有工作受访者每天工作时间在7小时以上，平均工作时间为8小时，其中43%以上的受访者工作时间超过9小时</p><p style=\"text-align: left;\">　　· 70%受访者认为在工作期间拥有10分钟“自我充电”时间不是一件困难的事情</p><p style=\"text-align: left;\">　　· 60%受访者认为在工作期间有10分钟休息时间足以为自己快速充电</p><p style=\"text-align: left;\">　　临床心理学家黄咏诗女士在发布会上分享为自己快速充电的实用技巧，她表示：“事实上，只要选择正确的休息方法，10分钟也足以为自己充电。以喝咖啡为例，我们可以使用心灵休息法 ── 静观呼吸，慢慢感受咖啡的温度和气味，如果能配合着聆听流水或海洋的声音，能够有效放松大脑及心灵。”</p><p style=\"text-align: left;\">　　这次调查结果反映出沪广深市民的希望在繁忙的工作中适时停下来，抽出10分钟喝杯咖啡、聆听音乐或小睡片刻，为自己充电。金山电池全新推出的“绿再十分充”超快速充电器仅需10分钟就能充好电，喝一杯咖啡的时间既能完成“自我充电”，也满足设备使用的用电需求，为提升工作效率和放松身心注入新能量。</p><p style=\"text-align: left;\">　　<strong>金山电池推出10分钟超快电池充电器*绿再十分充，以创新科技为市场带来革新体验</strong></p><p style=\"text-align: left;\">　　该问卷同时从沪广深市民对循环充电电池的使用方面进行了调查，以了解其对绿色生活方式的态度：</p><p style=\"text-align: left;\">　　· 87%受访者目前没有使用充电电池，其中61%表示会考虑使用充电电池</p><p style=\"text-align: left;\">　　· 58%受访者过往曾使用过充电电池，却只有20%左右市民仍在使用</p><p style=\"text-align: left;\">　　· 60%左右受访者认为充电电池尚未被广泛使用，主要障碍来自于充电时间过长、缺乏相关教育</p><p style=\"text-align: left;\">　　· 90%以上受访者认为充电电池充满电需要1小时或更长的时间</p><p style=\"text-align: left;\">　　金山电池一直致力于为大众提供安全可靠的充电电池，并与消费者的需求和生活方式一起演变及进步。今天，金山电池宣布推出10分钟超快电池充电器*绿再十分充，只需10分钟*即可将4粒绿再十分充充电电池充好电，充电速度比其他品牌提升3倍**。充电器的LED灯可以显示每粒电池的充电状态和模式，并提示用户是否错误插入已损坏电池或一次性电池。尽管其体型小巧，却具备多项创新科技 ，如拥有独特的充电算法以优化充电电流，并能根据各个电池类型、状况和温度用最短的时间为充电电池充好电;绿再十分充内置横流扇，有效防止电池温度过热和提供低噪音的充电环境等。<br></p>',
        11, 4, 1, 0, 1663322665, 1727071154, NULL);
COMMIT;


-- ----------------------------
-- Records of la_article_cate
-- ----------------------------
BEGIN;
INSERT INTO `la_article_cate_{tenantSn}`
VALUES (1, {tenantId}, '科技', 0, 1, 1663317280, 1663317280, NULL),
       (2, {tenantId}, '生活', 0, 1, 1663317280, 1663321464, NULL),
       (3, {tenantId}, '好物', 0, 1, 1727070858, 1727070858, NULL);
COMMIT;


-- ----------------------------
-- Records of la_tenant_pay_config
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_pay_config_{tenantSn}`
VALUES (1, {tenantId}, '点数支付', 1, '', 'resource/image/common/balance_pay.png', 128, '点数支付备注');
INSERT INTO `la_tenant_pay_config_{tenantSn}`
VALUES (2, {tenantId}, '微信支付', 2,
        '{\"interface_version\":\"v3\",\"merchant_type\":\"ordinary_merchant\",\"mch_id\":\"\",\"pay_sign_key\":\"\",\"apiclient_cert\":\"\",\"apiclient_key\":\"\"}',
        '/resource/image/common/wechat_pay.png', 123, '微信支付备注');
INSERT INTO `la_tenant_pay_config_{tenantSn}`
VALUES (3, {tenantId}, '支付宝支付', 3,
        '{\"mode\":\"normal_mode\",\"merchant_type\":\"ordinary_merchant\",\"app_id\":\"\",\"private_key\":\"\",\"ali_public_key\":\"\"}',
        '/resource/image/common/ali_pay.png', 123, '支付宝支付');
COMMIT;

-- ----------------------------
-- Records of la_tenant_pay_way
-- ----------------------------

BEGIN;
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (1, {tenantId}, 1, 1, 0, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (2, {tenantId}, 2, 1, 1, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (3, {tenantId}, 1, 2, 0, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (4, {tenantId}, 2, 2, 1, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (5, {tenantId}, 1, 3, 0, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (6, {tenantId}, 2, 3, 1, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (7, {tenantId}, 3, 3, 0, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (8, {tenantId}, 2, 4, 1, 1);
INSERT INTO `la_tenant_pay_way_{tenantSn}`
VALUES (9, {tenantId}, 3, 4, 0, 1);
COMMIT;


-- ----------------------------
-- Records of la_tenant_system_menu_{tenantSn}
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (4, {tenantId}, 0, 'M', '权限管理', 'el-icon-Lock', 300, '', 'permission', '', '', '', 0, 1, 0, 1656664556, 1710472802);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (5, {tenantId}, 0, 'C', '工作台', 'el-icon-Monitor', 1000, 'workbench/index', 'workbench', 'workbench/index', '', '', 0,
        1, 0, 1656664793, 1664354981);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (6, {tenantId}, 4, 'C', '菜单', 'el-icon-Operation', 100, 'auth.menu/lists', 'menu', 'permission/menu/index', '', '', 1,
        1, 0, 1656664960, 1710472994);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (7, {tenantId}, 4, 'C', '管理员', 'local-icon-shouyiren', 80, 'auth.admin/lists', 'admin', 'permission/admin/index', '',
        '', 0, 1, 0, 1656901567, 1710473013);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (8, {tenantId}, 4, 'C', '角色', 'el-icon-Female', 90, 'auth.role/lists', 'role', 'permission/role/index', '', '', 0, 1, 0,
        1656901660, 1710473000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (12, {tenantId}, 8, 'A', '新增', '', 1, 'auth.role/add', '', '', '', '', 0, 1, 0, 1657001790, 1663750625);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (14, {tenantId}, 8, 'A', '编辑', '', 1, 'auth.role/edit', '', '', '', '', 0, 1, 0, 1657001924, 1663750631);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (15, {tenantId}, 8, 'A', '删除', '', 1, 'auth.role/delete', '', '', '', '', 0, 1, 0, 1657001982, 1663750637);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (16, {tenantId}, 6, 'A', '新增', '', 1, 'auth.menu/add', '', '', '', '', 0, 1, 0, 1657072523, 1663750565);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (17, {tenantId}, 6, 'A', '编辑', '', 1, 'auth.menu/edit', '', '', '', '', 0, 1, 0, 1657073955, 1663750570);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (18, {tenantId}, 6, 'A', '删除', '', 1, 'auth.menu/delete', '', '', '', '', 0, 1, 0, 1657073987, 1663750578);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (19, {tenantId}, 7, 'A', '新增', '', 1, 'auth.admin/add', '', '', '', '', 0, 1, 0, 1657074035, 1663750596);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (20, {tenantId}, 7, 'A', '编辑', '', 1, 'auth.admin/edit', '', '', '', '', 0, 1, 0, 1657074071, 1663750603);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (21, {tenantId}, 7, 'A', '删除', '', 1, 'auth.admin/delete', '', '', '', '', 0, 1, 0, 1657074108, 1663750609);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (25, {tenantId}, 0, 'M', '组织管理', 'el-icon-OfficeBuilding', 400, '', 'organization', '', '', '', 0, 1, 0, 1657099914,
        1710472797);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (26, {tenantId}, 25, 'C', '部门管理', 'el-icon-Coordinate', 100, 'dept.dept/lists', 'department',
        'organization/department/index', '', '', 1, 1, 0, 1657099989, 1710472962);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (27, {tenantId}, 25, 'C', '岗位管理', 'el-icon-PriceTag', 90, 'dept.jobs/lists', 'post', 'organization/post/index', '',
        '', 0, 1, 0, 1657100044, 1710472967);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (28, {tenantId}, 0, 'M', '系统设置', 'el-icon-Setting', 200, '', 'setting', '', '', '', 0, 1, 0, 1657100164, 1710472807);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (29, {tenantId}, 28, 'M', '网站设置', 'el-icon-Basketball', 100, '', 'website', '', '', '', 0, 1, 0, 1657100230,
        1710473049);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (30, {tenantId}, 29, 'C', '网站信息', '', 1, 'setting.web.web_setting/getWebsite', 'information',
        'setting/website/information', '', '', 0, 1, 0, 1657100306, 1657164412);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (31, {tenantId}, 29, 'C', '网站备案', '', 1, 'setting.web.web_setting/getCopyright', 'filing', 'setting/website/filing',
        '', '', 0, 1, 0, 1657100434, 1657164723);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (32, {tenantId}, 29, 'C', '政策协议', '', 1, 'setting.web.web_setting/getAgreement', 'protocol',
        'setting/website/protocol', '', '', 0, 1, 0, 1657100571, 1657164770);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (35, {tenantId}, 28, 'M', '系统维护', 'el-icon-SetUp', 50, '', 'system', '', '', '', 0, 1, 0, 1657161569, 1710473122);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (37, {tenantId}, 35, 'C', '系统缓存', '', 80, '', 'cache', 'setting/system/cache', '', '', 0, 1, 0, 1657161896,
        1710473258);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (45, {tenantId}, 26, 'A', '新增', '', 1, 'dept.dept/add', '', '', '', '', 0, 1, 0, 1657163548, 1663750492);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (46, {tenantId}, 26, 'A', '编辑', '', 1, 'dept.dept/edit', '', '', '', '', 0, 1, 0, 1657163599, 1663750498);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (47, {tenantId}, 26, 'A', '删除', '', 1, 'dept.dept/delete', '', '', '', '', 0, 1, 0, 1657163687, 1663750504);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (48, {tenantId}, 27, 'A', '新增', '', 1, 'dept.jobs/add', '', '', '', '', 0, 1, 0, 1657163778, 1663750524);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (49, {tenantId}, 27, 'A', '编辑', '', 1, 'dept.jobs/edit', '', '', '', '', 0, 1, 0, 1657163800, 1663750530);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (50, {tenantId}, 27, 'A', '删除', '', 1, 'dept.jobs/delete', '', '', '', '', 0, 1, 0, 1657163820, 1663750535);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (51, {tenantId}, 30, 'A', '保存', '', 1, 'setting.web.web_setting/setWebsite', '', '', '', '', 0, 1, 0, 1657164469,
        1663750649);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (52, {tenantId}, 31, 'A', '保存', '', 1, 'setting.web.web_setting/setCopyright', '', '', '', '', 0, 1, 0, 1657164692,
        1663750657);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (53, {tenantId}, 32, 'A', '保存', '', 1, 'setting.web.web_setting/setAgreement', '', '', '', '', 0, 1, 0, 1657164824,
        1663750665);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (54, {tenantId}, 28, 'C', '存储设置', 'el-icon-FolderOpened', 70, 'setting.storage/lists', 'storage',
        'setting/storage/index', '', '', 0, 1, 0, 1657165303, 1663750673);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (55, {tenantId}, 54, 'A', '设置', '', 1, 'setting.storage/setup', '', '', '', '', 0, 1, 0, 1657165303,
        1663750673);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (56, {tenantId}, 54, 'A', '切换', '', 1, 'setting.storage/change', '', '', '', '', 0, 1, 0, 1657165303,
        1663750673);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (57, {tenantId}, 54, 'A', '详情', '', 1, 'setting.storage/detail', '', '', '', '', 0, 1, 0, 1657165303,
        1663750673);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (61, {tenantId}, 37, 'A', '清除系统缓存', '', 1, 'setting.system.cache/clear', '', '', '', '', 0, 1, 0, 1657173837,
        1657173939);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (63, {tenantId}, 158, 'M', '素材管理', 'el-icon-Picture', 0, '', 'material', '', '', '', 0, 1, 0, 1657507133, 1710472243);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (64, {tenantId}, 63, 'C', '素材中心', 'el-icon-PictureRounded', 0, '', 'index', 'material/index', '', '', 0, 1, 0,
        1657507296, 1664355653);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (66, {tenantId}, 26, 'A', '详情', '', 0, 'dept.dept/detail', '', '', '', '', 0, 1, 0, 1663725459, 1663750516);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (67, {tenantId}, 27, 'A', '详情', '', 0, 'dept.jobs/detail', '', '', '', '', 0, 1, 0, 1663725514, 1663750559);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (68, {tenantId}, 6, 'A', '详情', '', 0, 'auth.menu/detail', '', '', '', '', 0, 1, 0, 1663725564, 1663750584);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (69, {tenantId}, 7, 'A', '详情', '', 0, 'auth.admin/detail', '', '', '', '', 0, 1, 0, 1663725623, 1663750615);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (70, {tenantId}, 158, 'M', '文章资讯', 'el-icon-ChatLineSquare', 90, '', 'article', '', '', '', 0, 1, 0, 1663749965,
        1710471867);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (71, {tenantId}, 70, 'C', '文章管理', 'el-icon-ChatDotSquare', 0, 'article.article/lists', 'lists', 'article/lists/index',
        '', '', 0, 1, 0, 1663750101, 1664354615);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (72, {tenantId}, 70, 'C', '文章添加/编辑', '', 0, 'article.article/add:edit', 'lists/edit', 'article/lists/edit',
        '/article/lists', '', 0, 0, 0, 1663750153, 1664356275);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (73, {tenantId}, 70, 'C', '文章栏目', 'el-icon-CollectionTag', 0, 'article.articleCate/lists', 'column',
        'article/column/index', '', '', 1, 1, 0, 1663750287, 1664354678);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (74, {tenantId}, 71, 'A', '新增', '', 0, 'article.article/add', '', '', '', '', 0, 1, 0, 1663750335, 1663750335);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (75, {tenantId}, 71, 'A', '详情', '', 0, 'article.article/detail', '', '', '', '', 0, 1, 0, 1663750354, 1663750383);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (76, {tenantId}, 71, 'A', '删除', '', 0, 'article.article/delete', '', '', '', '', 0, 1, 0, 1663750413, 1663750413);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (77, {tenantId}, 71, 'A', '修改状态', '', 0, 'article.article/updateStatus', '', '', '', '', 0, 1, 0, 1663750442,
        1663750442);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (78, {tenantId}, 73, 'A', '添加', '', 0, 'article.articleCate/add', '', '', '', '', 0, 1, 0, 1663750483, 1663750483);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (79, {tenantId}, 73, 'A', '删除', '', 0, 'article.articleCate/delete', '', '', '', '', 0, 1, 0, 1663750895, 1663750895);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (80, {tenantId}, 73, 'A', '详情', '', 0, 'article.articleCate/detail', '', '', '', '', 0, 1, 0, 1663750913, 1663750913);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (81, {tenantId}, 73, 'A', '修改状态', '', 0, 'article.articleCate/updateStatus', '', '', '', '', 0, 1, 0, 1663750936,
        1663750936);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (82, {tenantId}, 0, 'M', '渠道设置', 'el-icon-Message', 500, '', 'channel', '', '', '', 0, 1, 0, 1663754084, 1710472649);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (83, {tenantId}, 82, 'C', 'h5设置', 'el-icon-Cellphone', 100, 'channel.web_page_setting/getConfig', 'h5', 'channel/h5',
        '', '', 0, 1, 0, 1663754158, 1710472929);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (84, {tenantId}, 83, 'A', '保存', '', 0, 'channel.web_page_setting/setConfig', '', '', '', '', 0, 1, 0, 1663754259,
        1663754259);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (85, {tenantId}, 82, 'M', '微信公众号', 'local-icon-dingdan', 80, '', 'wx_oa', '', '', '', 0, 1, 0, 1663755470,
        1710472946);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (86, {tenantId}, 85, 'C', '公众号配置', '', 0, 'channel.official_account_setting/getConfig', 'config',
        'channel/wx_oa/config', '', '', 0, 1, 0, 1663755663, 1664355450);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (87, {tenantId}, 85, 'C', '菜单管理', '', 0, 'channel.official_account_menu/detail', 'menu', 'channel/wx_oa/menu', '', '',
        0, 1, 0, 1663755767, 1664355456);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (88, {tenantId}, 86, 'A', '保存', '', 0, 'channel.official_account_setting/setConfig', '', '', '', '', 0, 1, 0,
        1663755799, 1663755799);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (89, {tenantId}, 86, 'A', '保存并发布', '', 0, 'channel.official_account_menu/save', '', '', '', '', 0, 1, 0, 1663756490,
        1663756490);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (90, {tenantId}, 85, 'C', '关注回复', '', 0, 'channel.official_account_reply/lists', 'follow',
        'channel/wx_oa/reply/follow_reply', '', '', 0, 1, 0, 1663818358, 1663818366);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (91, {tenantId}, 85, 'C', '关键字回复', '', 0, '', 'keyword', 'channel/wx_oa/reply/keyword_reply', '', '', 0, 1, 0,
        1663818445, 1663818445);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (93, {tenantId}, 85, 'C', '默认回复', '', 0, '', 'default', 'channel/wx_oa/reply/default_reply', '', '', 0, 1, 0,
        1663818580, 1663818580);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (94, {tenantId}, 82, 'C', '微信小程序', 'local-icon-weixin', 90, 'channel.mnp_settings/getConfig', 'weapp',
        'channel/weapp', '', '', 0, 1, 0, 1663831396, 1710472941);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (95, {tenantId}, 94, 'A', '保存', '', 0, 'channel.mnp_settings/setConfig', '', '', '', '', 0, 1, 0, 1663831436,
        1663831436);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (96, {tenantId}, 0, 'M', '装修管理', 'el-icon-Brush', 600, '', 'decoration', '', '', '', 0, 1, 0, 1663834825, 1710472099);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (97, {tenantId}, 175, 'C', '页面装修', 'el-icon-CopyDocument', 100, 'decorate.page/detail', 'pages',
        'decoration/pages/index', '', '', 0, 1, 0, 1663834879, 1710929256);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (98, {tenantId}, 97, 'A', '保存', '', 0, 'decorate.page/save', '', '', '', '', 0, 1, 0, 1663834956, 1663834956);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (99, {tenantId}, 175, 'C', '底部导航', 'el-icon-Position', 90, 'decorate.tabbar/detail', 'tabbar', 'decoration/tabbar',
        '', '', 0, 1, 0, 1663835004, 1710929262);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (100, {tenantId}, 99, 'A', '保存', '', 0, 'decorate.tabbar/save', '', '', '', '', 0, 1, 0, 1663835018, 1663835018);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (101, {tenantId}, 158, 'M', '消息管理', 'el-icon-ChatDotRound', 80, '', 'message', '', '', '', 0, 1, 0, 1663838602,
        1710471874);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (102, {tenantId}, 101, 'C', '通知设置', '', 0, 'notice.notice/settingLists', 'notice', 'message/notice/index', '', '', 0,
        1, 0, 1663839195, 1663839195);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (103, {tenantId}, 102, 'A', '详情', '', 0, 'notice.notice/detail', '', '', '', '', 0, 1, 0, 1663839537, 1663839537);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (104, {tenantId}, 101, 'C', '通知设置编辑', '', 0, 'notice.notice/set', 'notice/edit', 'message/notice/edit',
        '/message/notice', '', 0, 0, 0, 1663839873, 1663898477);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (105, {tenantId}, 71, 'A', '编辑', '', 0, 'article.article/edit', '', '', '', '', 0, 1, 0, 1663840043, 1663840053);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (107, {tenantId}, 101, 'C', '短信设置', '', 0, 'notice.sms_config/getConfig', 'short_letter',
        'message/short_letter/index', '', '', 0, 1, 0, 1663898591, 1664355708);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (108, {tenantId}, 107, 'A', '设置', '', 0, 'notice.sms_config/setConfig', '', '', '', '', 0, 1, 0, 1663898644,
        1663898644);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (109, {tenantId}, 107, 'A', '详情', '', 0, 'notice.sms_config/detail', '', '', '', '', 0, 1, 0, 1663898661, 1663898661);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (110, {tenantId}, 28, 'C', '热门搜索', 'el-icon-Search', 60, 'setting.hot_search/getConfig', 'search',
        'setting/search/index', '', '', 0, 1, 0, 1663901821, 1710473109);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (111, {tenantId}, 110, 'A', '保存', '', 0, 'setting.hot_search/setConfig', '', '', '', '', 0, 1, 0, 1663901856,
        1663901856);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (112, {tenantId}, 28, 'M', '用户设置', 'local-icon-keziyuyue', 90, '', 'user', '', '', '', 0, 1, 0, 1663903302,
        1710473056);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (113, {tenantId}, 112, 'C', '用户设置', '', 0, 'setting.user.user/getConfig', 'setup', 'setting/user/setup', '', '', 0, 1,
        0, 1663903506, 1663903506);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (114, {tenantId}, 113, 'A', '保存', '', 0, 'setting.user.user/setConfig', '', '', '', '', 0, 1, 0, 1663903522,
        1663903522);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (115, {tenantId}, 112, 'C', '登录注册', '', 0, 'setting.user.user/getRegisterConfig', 'login_register',
        'setting/user/login_register', '', '', 0, 1, 0, 1663903832, 1663903832);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (116, {tenantId}, 115, 'A', '保存', '', 0, 'setting.user.user/setRegisterConfig', '', '', '', '', 0, 1, 0, 1663903852,
        1663903852);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (117, {tenantId}, 0, 'M', '用户管理', 'el-icon-User', 900, '', 'consumer', '', '', '', 0, 1, 0, 1663904351, 1710472074);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (118, {tenantId}, 117, 'C', '用户列表', 'local-icon-user_guanli', 100, 'user.user/lists', 'lists', 'consumer/lists/index',
        '', '', 0, 1, 0, 1663904392, 1710471845);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (119, {tenantId}, 117, 'C', '用户详情', '', 90, 'user.user/detail', 'lists/detail', 'consumer/lists/detail',
        '/consumer/lists', '', 0, 0, 0, 1663904470, 1710471851);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (120, {tenantId}, 119, 'A', '编辑', '', 0, 'user.user/edit', '', '', '', '', 0, 1, 0, 1663904499, 1663904499);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (9016, {tenantId}, 117, 'C', '任务记录', 'el-icon-List', 90, 'ai_task/lists', 'task', 'consumer/task/index',
        '', '', 0, 1, 0, 1727700000, 1727700000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (9017, {tenantId}, 9016, 'A', '详情', '', 1, 'ai_task/detail', '', '', '', '', 0, 1, 0, 1727700000,
        1727700000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (140, {tenantId}, 82, 'C', '微信开放平台', 'local-icon-notice_buyer', 70, 'channel.open_setting/getConfig',
        'open_setting', 'channel/open_setting', '', '', 0, 1, 0, 1666085713, 1710472951);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (141, {tenantId}, 140, 'A', '保存', '', 0, 'channel.open_setting/setConfig', '', '', '', '', 0, 1, 0, 1666085751,
        1666085776);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (142, {tenantId}, 176, 'C', 'PC端装修', 'el-icon-Monitor', 8, '', 'pc', 'decoration/pc', '', '', 0, 0, 0, 1668423284,
        1710901602);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (148, {tenantId}, 0, 'M', '模板示例', 'el-icon-SetUp', 100, '', 'template', '', '', '', 0, 1, 0, 1670206819, 1710472811);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (149, {tenantId}, 148, 'M', '组件示例', 'el-icon-Coin', 0, '', 'component', '', '', '', 0, 1, 0, 1670207182, 1670207244);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (150, {tenantId}, 149, 'C', '富文本', '', 90, '', 'rich_text', 'template/component/rich_text', '', '', 0, 1, 0,
        1670207751, 1710473315);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (151, {tenantId}, 149, 'C', '上传文件', '', 80, '', 'upload', 'template/component/upload', '', '', 0, 1, 0, 1670208925,
        1710473322);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (152, {tenantId}, 149, 'C', '图标', '', 100, '', 'icon', 'template/component/icon', '', '', 0, 1, 0, 1670230069,
        1710473306);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (153, {tenantId}, 149, 'C', '文件选择器', '', 60, '', 'file', 'template/component/file', '', '', 0, 1, 0, 1670232129,
        1710473341);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (154, {tenantId}, 149, 'C', '链接选择器', '', 50, '', 'link', 'template/component/link', '', '', 0, 1, 0, 1670292636,
        1710473346);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (155, {tenantId}, 149, 'C', '超出自动打点', '', 40, '', 'overflow', 'template/component/overflow', '', '', 0, 1, 0,
        1670292883, 1710473351);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (156, {tenantId}, 149, 'C', '悬浮input', '', 70, '', 'popover_input', 'template/component/popover_input', '', '', 0, 1, 0,
        1670293336, 1710473329);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (157, {tenantId}, 119, 'A', '点数调整', '', 0, 'user.user/adjustMoney', '', '', '', '', 0, 1, 0, 1677143088, 1677143088);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (158, {tenantId}, 0, 'M', '应用管理', 'el-icon-Postcard', 800, '', 'app', '', '', '', 0, 1, 0, 1677143430, 1710472079);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (159, {tenantId}, 179, 'C', '用户充值', 'local-icon-fukuan', 80, 'recharge.recharge/getConfig', 'recharge',
        'app/recharge/index', '', '', 0, 1, 0, 1677144284, 1710471860);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (160, {tenantId}, 159, 'A', '保存', '', 0, 'recharge.recharge/setConfig', '', '', '', '', 0, 1, 0, 1677145012,
        1677145012);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (161, {tenantId}, 28, 'M', '支付设置', 'local-icon-set_pay', 80, '', 'pay', '', '', '', 0, 1, 0, 1677148075, 1710473061);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (162, {tenantId}, 161, 'C', '支付方式', '', 0, 'setting.pay.pay_way/getPayWay', 'method', 'setting/pay/method/index', '',
        '', 0, 1, 0, 1677148207, 1677148207);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (163, {tenantId}, 161, 'C', '支付配置', '', 0, 'setting.pay.pay_config/lists', 'config', 'setting/pay/config/index', '',
        '', 0, 1, 0, 1677148260, 1677148374);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (164, {tenantId}, 162, 'A', '设置支付方式', '', 0, 'setting.pay.pay_way/setPayWay', '', '', '', '', 0, 1, 0, 1677219624,
        1677219624);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (165, {tenantId}, 163, 'A', '配置', '', 0, 'setting.pay.pay_config/setConfig', '', '', '', '', 0, 1, 0, 1677219655,
        1677219655);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (166, {tenantId}, 0, 'M', '财务管理', 'local-icon-user_gaikuang', 700, '', 'finance', '', '', '', 0, 1, 0, 1677552269,
        1710472085);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (167, {tenantId}, 166, 'C', '充值记录', 'el-icon-Wallet', 90, 'recharge.recharge/lists', 'recharge_record',
        'finance/recharge_record', '', '', 0, 1, 0, 1677552757, 1710472902);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (168, {tenantId}, 166, 'C', '点数明细', 'local-icon-qianbao', 100, 'finance.account_log/lists', 'balance_details',
        'finance/balance_details', '', '', 0, 1, 0, 1677552976, 1710472894);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (169, {tenantId}, 167, 'A', '退款', '', 0, 'recharge.recharge/refund', '', '', '', '', 0, 1, 0, 1677809715, 1677809715);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (170, {tenantId}, 166, 'C', '退款记录', 'local-icon-heshoujilu', 0, 'finance.refund/record', 'refund_record',
        'finance/refund_record', '', '', 0, 1, 0, 1677811271, 1677811271);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (171, {tenantId}, 170, 'A', '重新退款', '', 0, 'recharge.recharge/refundAgain', '', '', '', '', 0, 1, 0, 1677811295,
        1677811295);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (172, {tenantId}, 170, 'A', '退款日志', '', 0, 'finance.refund/log', '', '', '', '', 0, 1, 0, 1677811361, 1677811361);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (173, {tenantId}, 175, 'C', '系统风格', 'el-icon-Brush', 80, '', 'style', 'decoration/style/style', '', '', 0, 1, 0,
        1681635044, 1710929278);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (175, {tenantId}, 96, 'M', '移动端', '', 100, '', 'mobile', '', '', '', 0, 1, 0, 1710901543, 1710929294);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (176, {tenantId}, 96, 'M', 'PC端', '', 90, '', 'pc', '', '', '', 0, 1, 0, 1710901592, 1710929299);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (177, {tenantId},29, 'C', '站点统计', '', 0, 'setting.web.web_setting/getSiteStatistics', 'statistics', 'setting/website/statistics', '', '', 0, 1, 0, 1726841481, 1726843434);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (178, {tenantId},177, 'A', '保存', '', 0, 'setting.web.web_setting/saveSiteStatistics', '', '', '', '', 1, 1, 0, 1726841507, 1726841507);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (179, {tenantId}, 166, 'M', '套餐管理', 'el-icon-Tickets', 110, '', 'package', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (180, {tenantId}, 188, 'A', '新增', '', 0, 'finance.membership_plan/add', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (181, {tenantId}, 188, 'A', '编辑', '', 0, 'finance.membership_plan/edit', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (182, {tenantId}, 188, 'A', '删除', '', 0, 'finance.membership_plan/delete', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (183, {tenantId}, 188, 'A', '详情', '', 0, 'finance.membership_plan/detail', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (184, {tenantId}, 188, 'A', '可关联应用', '', 0, 'finance.membership_plan/apps', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (185, {tenantId}, 166, 'C', '订单管理', 'el-icon-Document', 105, 'finance.membership_order/lists', 'membership_order',
        'finance/membership_order', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (186, {tenantId}, 185, 'A', '详情', '', 0, 'finance.membership_order/detail', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (187, {tenantId}, 179, 'C', '算力套餐', 'el-icon-Coin', 100, 'finance.recharge_package/lists', 'recharge_package',
        'finance/recharge_package', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (188, {tenantId}, 179, 'C', '会员套餐', 'el-icon-Medal', 90, 'finance.membership_plan/lists', 'membership_plan',
        'finance/membership_plan', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (189, {tenantId}, 187, 'A', '新增', '', 0, 'finance.recharge_package/add', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (190, {tenantId}, 187, 'A', '编辑', '', 0, 'finance.recharge_package/edit', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (191, {tenantId}, 187, 'A', '删除', '', 0, 'finance.recharge_package/delete', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);
INSERT INTO `la_tenant_system_menu_{tenantSn}`
VALUES (192, {tenantId}, 187, 'A', '详情', '', 0, 'finance.recharge_package/detail', '', '', '', '', 0, 1, 0, 1778000000, 1778000000);

DELETE FROM `la_membership_plan_app`
WHERE `tenant_id` = {tenantId}
  AND `app_code` IN ('aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_canvas', 'aigc_llm');

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
  {tenantId},
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
WHERE NOT EXISTS (
  SELECT 1 FROM `la_membership_plan`
  WHERE `tenant_id` = {tenantId} AND `name` = '免费会员'
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
  {tenantId},
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
FROM (
  SELECT '基础会员' AS `name`, '适合轻量创作用户，赠送基础积分' AS `description`, 19.90 AS `monthly_price`, 199.00 AS `yearly_price`, 29.90 AS `monthly_market_price`, 299.00 AS `yearly_market_price`, 100.00 AS `monthly_bonus_points`, 1500.00 AS `yearly_bonus_points`, '["每月赠送100积分","按年开通赠送1500积分","适合个人轻量创作"]' AS `features`, 0 AS `is_recommend`, 90 AS `sort`
  UNION ALL
  SELECT '高级会员', '适合高频创作用户，赠送更多积分', 39.90, 399.00, 69.90, 699.00, 300.00, 4200.00, '["每月赠送300积分","按年开通赠送4200积分","适合高频图文与视频创作"]', 1, 80
) plans
WHERE NOT EXISTS (
  SELECT 1 FROM `la_membership_plan`
  WHERE `tenant_id` = {tenantId} AND `name` = plans.`name`
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
  {tenantId},
  packages.`name`,
  packages.`points`,
  packages.`amount`,
  packages.`market_amount`,
  packages.`is_recommend`,
  1,
  packages.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM (
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
  SELECT 1 FROM `la_recharge_package`
  WHERE `tenant_id` = {tenantId} AND `name` = packages.`name`
);
COMMIT;

-- ----------------------------

-- Default AIGC apps for new tenants

-- ----------------------------

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
VALUES
({tenantId},'aigc_image','1.1.3','paid','on','enabled',0,1778000000,1778000000),
({tenantId},'aigc_video','1.0.1','paid','on','enabled',0,1778000000,1778000000),
({tenantId},'aigc_digital_human','1.0.1','paid','on','enabled',0,1778000000,1778000000),
({tenantId},'aigc_canvas','1.0.1','paid','on','enabled',0,1778000000,1778000000),
({tenantId},'aigc_llm','1.1.1','paid','on','enabled',0,1778000000,1778000000)
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu_{tenantSn}` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9100,{tenantId},0,'M','AIGC生图','el-icon-Picture',100,'','aigc-image','','','',0,1,0,'aigc_image','app','aigc_image',0,1778000000,1778000000),
(9101,{tenantId},9100,'C','生图任务','',0,'app.aigc_image.admin_task/lists','task','apps/aigc_image/task','','',0,1,0,'aigc_image','app','aigc_image_task',0,1778000000,1778000000),
(9102,{tenantId},9100,'C','案例广场','',0,'app.aigc_image.case/lists','case','apps/aigc_image/case','','',0,1,0,'aigc_image','app','aigc_image_case',0,1778000000,1778000000),
(9103,{tenantId},9100,'C','通道调价','',0,'app.aigc_image.channel/lists','channel-price','apps/aigc_image/channel-price','','',0,1,0,'aigc_image','app','aigc_image_channel_price',0,1778000000,1778000000),
(9104,{tenantId},9100,'C','用量统计','',0,'app.aigc_image.admin/stat','stat','apps/aigc_image/stat','','',0,1,0,'aigc_image','app','aigc_image_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu_{tenantSn}` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9105,{tenantId},0,'M','AIGC视频','el-icon-Picture',100,'','aigc-video','','','',0,1,0,'aigc_video','app','aigc_video',0,1778000000,1778000000),
(9106,{tenantId},9105,'C','视频任务','',0,'app.aigc_video.admin_task/lists','task','apps/aigc_video/task','','',0,1,0,'aigc_video','app','aigc_video_task',0,1778000000,1778000000),
(9108,{tenantId},9105,'C','通道调价','',0,'app.aigc_video.channel/lists','channel-price','apps/aigc_video/channel-price','','',0,1,0,'aigc_video','app','aigc_video_channel_price',0,1778000000,1778000000),
(9109,{tenantId},9105,'C','用量统计','',0,'app.aigc_video.admin/stat','stat','apps/aigc_video/stat','','',0,1,0,'aigc_video','app','aigc_video_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu_{tenantSn}` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9110,{tenantId},0,'M','数字人视频','el-icon-Picture',100,'','aigc-digital-human','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human',0,1778000000,1778000000),
(9111,{tenantId},9110,'C','合成任务','',0,'app.aigc_digital_human.admin_task/lists','task','apps/aigc_digital_human/task','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_task',0,1778000000,1778000000),
(9113,{tenantId},9110,'M','形象管理','',0,'','avatar-manage','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_avatar_manage',0,1778000000,1778000000),
(9114,{tenantId},9113,'C','公共形象','',0,'app.aigc_digital_human.public_avatar/lists','public-avatar','apps/aigc_digital_human/public-avatar','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_public_avatar',0,1778000000,1778000000),
(9115,{tenantId},9113,'C','用户形象','',0,'app.aigc_digital_human.user_avatar/lists','user-avatar','apps/aigc_digital_human/user-avatar','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_user_avatar',0,1778000000,1778000000),
(9116,{tenantId},9110,'M','音色管理','',0,'','voice-manage','','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_voice_manage',0,1778000000,1778000000),
(9117,{tenantId},9116,'C','公共音色','',0,'app.aigc_digital_human.public_voice/lists','public-voice','apps/aigc_digital_human/public-voice','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_public_voice',0,1778000000,1778000000),
(9118,{tenantId},9116,'C','用户音色','',0,'app.aigc_digital_human.user_voice/lists','user-voice','apps/aigc_digital_human/user-voice','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_user_voice',0,1778000000,1778000000),
(9119,{tenantId},9110,'C','通道调价','',0,'app.aigc_digital_human.channel/lists','channel-price','apps/aigc_digital_human/channel-price','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_channel_price',0,1778000000,1778000000),
(9120,{tenantId},9110,'C','用量统计','',0,'app.aigc_digital_human.admin/stat','stat','apps/aigc_digital_human/stat','','',0,1,0,'aigc_digital_human','app','aigc_digital_human_stat',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu_{tenantSn}` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9121,{tenantId},0,'M','无限画布','el-icon-Share',96,'','aigc-canvas','','','',0,1,0,'aigc_canvas','app','aigc_canvas',0,1778000000,1778000000),
(9122,{tenantId},9121,'C','用量统计','',0,'app.aigc_canvas.admin/stat','stat','apps/aigc_canvas/stat','','',0,1,0,'aigc_canvas','app','aigc_canvas_stat',0,1778000000,1778000000),
(9123,{tenantId},9121,'C','项目管理','',0,'app.aigc_canvas.admin_project/lists','project','apps/aigc_canvas/project','','',0,1,0,'aigc_canvas','app','aigc_canvas_project',0,1778000000,1778000000),
(9124,{tenantId},9121,'C','运行记录','',0,'app.aigc_canvas.admin_run/lists','run','apps/aigc_canvas/run','','',0,1,0,'aigc_canvas','app','aigc_canvas_run',0,1778000000,1778000000),
(9125,{tenantId},9121,'C','依赖状态','',0,'app.aigc_canvas.config/dependencies','dependencies','apps/aigc_canvas/dependencies','','',0,1,0,'aigc_canvas','app','aigc_canvas_dependency',0,1778000000,1778000000);

INSERT INTO `la_tenant_system_menu_{tenantSn}` (`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9126,{tenantId},0,'M','AIGC对话','el-icon-ChatDotRound',100,'','aigc-llm','','','',0,1,0,'aigc_llm','app','aigc_llm',0,1778000000,1778000000),
(9127,{tenantId},9126,'C','基础配置','',0,'app.aigc_llm.config/detail','config','apps/aigc_llm/config','','',0,1,0,'aigc_llm','app','aigc_llm_config',0,1778000000,1778000000),
(9128,{tenantId},9126,'C','通道配置','',0,'app.aigc_llm.channel/lists','channel','apps/aigc_llm/channel','','',0,1,0,'aigc_llm','app','aigc_llm_channel',0,1778000000,1778000000),
(9129,{tenantId},9126,'C','模型配置','',0,'app.aigc_llm.model/lists','model','apps/aigc_llm/model','','',0,1,0,'aigc_llm','app','aigc_llm_model',0,1778000000,1778000000),
(9130,{tenantId},9126,'C','会话记录','',0,'app.aigc_llm.admin_session/lists','session','apps/aigc_llm/session','','',0,1,0,'aigc_llm','app','aigc_llm_session',0,1778000000,1778000000),
(9131,{tenantId},9126,'C','敏感词','',0,'app.aigc_llm.admin/sensitiveWord','sensitive-word','apps/aigc_llm/sensitive-word','','',0,1,0,'aigc_llm','app','aigc_llm_sensitive_word',0,1778000000,1778000000),
(9132,{tenantId},9126,'C','用量统计','',0,'app.aigc_llm.admin/stat','stat','apps/aigc_llm/stat','','',0,1,0,'aigc_llm','app','aigc_llm_stat',0,1778000000,1778000000);

-- ----------------------------
-- Records of la_tenant_dept
-- ----------------------------
BEGIN;
INSERT INTO `la_tenant_dept_{tenantSn}`
VALUES (1, {tenantId}, '公司', 0, 0, 'boss', '12345698745', 1, 1650592684, 1653640368, NULL);
COMMIT;


-- ----------------------------
-- Records of la_decorate_tabbar
-- ----------------------------
BEGIN;
INSERT INTO `la_decorate_tabbar_{tenantSn}`
VALUES (1, {tenantId}, '首页', 'resource/image/tenantapi/default/tabbar_home_sel.png',
        'resource/image/tenantapi/default/tabbar_home.png',
        '{\"path\":\"/pages/index/index\",\"name\":\"系统首页\",\"type\":\"shop\"}', 1, 1662688157, 1662688157);
INSERT INTO `la_decorate_tabbar_{tenantSn}`
VALUES (2, {tenantId}, '资讯', 'resource/image/tenantapi/default/tabbar_text_sel.png',
        'resource/image/tenantapi/default/tabbar_text.png',
        '{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\",\"canTab\":\"1\"}', 1, 1662688157,
        1662688157);
INSERT INTO `la_decorate_tabbar_{tenantSn}`
VALUES (3, {tenantId}, '我的', 'resource/image/tenantapi/default/tabbar_me_sel.png',
        'resource/image/tenantapi/default/tabbar_me.png',
        '{\"path\":\"/pages/user/user\",\"name\":\"个人中心\",\"type\":\"shop\",\"canTab\":\"1\"}', 1, 1662688157,
        1662688157);
COMMIT;

-- ----------------------------
-- Records of la_decorate_page
-- ----------------------------
BEGIN;
INSERT INTO `la_decorate_page_{tenantSn}`
VALUES (1, {tenantId}, 1, '系统首页',
        '[{\"title\":\"搜索\",\"name\":\"search\",\"disabled\":1,\"content\":{},\"styles\":{}},{\"title\":\"首页轮播图\",\"name\":\"banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/banner001.png\",\"name\":\"\",\"link\":{\"id\":6,\"name\":\"来自瓷器的爱\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":6},\"type\":\"article\"},\"is_show\":\"1\",\"bg\":\"/resource/image/tenantapi/default/banner001_bg.png\"},{\"image\":\"/resource/image/tenantapi/default/banner002.png\",\"name\":\"\",\"link\":{\"id\":3,\"name\":\"金山电池公布“沪广深市民绿色生活方式”调查结果\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":3},\"type\":\"article\"},\"is_show\":\"1\",\"bg\":\"/resource/image/tenantapi/default/banner002_bg.png\"},{\"is_show\":\"1\",\"image\":\"/resource/image/tenantapi/default/banner003.png\",\"name\":\"\",\"link\":{\"id\":1,\"name\":\"让生活更精致！五款居家好物推荐，实用性超高\",\"path\":\"/pages/news_detail/news_detail\",\"query\":{\"id\":1},\"type\":\"article\"},\"bg\":\"/resource/image/tenantapi/default/banner003_bg.png\"}],\"style\":1,\"bg_style\":1},\"styles\":{}},{\"title\":\"导航菜单\",\"name\":\"nav\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/nav01.png\",\"name\":\"资讯中心\",\"link\":{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\",\"canTab\":true},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav03.png\",\"name\":\"个人设置\",\"link\":{\"path\":\"/pages/user_set/user_set\",\"name\":\"个人设置\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav02.png\",\"name\":\"我的收藏\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav05.png\",\"name\":\"关于我们\",\"link\":{\"path\":\"/pages/as_us/as_us\",\"name\":\"关于我们\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/nav04.png\",\"name\":\"联系客服\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"}],\"style\":2,\"per_line\":5,\"show_line\":2},\"styles\":{}},{\"title\":\"首页中部轮播图\",\"name\":\"middle-banner\",\"content\":{\"enabled\":1,\"data\":[{\"is_show\":\"1\",\"image\":\"/resource/image/tenantapi/default/index_ad01.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/agreement/agreement\",\"name\":\"隐私政策\",\"query\":{\"type\":\"privacy\"},\"type\":\"shop\"}}]},\"styles\":{}},{\"id\":\"l84almsk2uhyf\",\"title\":\"资讯\",\"name\":\"news\",\"disabled\":1,\"content\":{},\"styles\":{}}]',
        '[{\"title\":\"页面设置\",\"name\":\"page-meta\",\"content\":{\"title\":\"首页\",\"bg_type\":\"0\",\"bg_color\":\"\",\"bg_image\":\"\",\"text_color\":\"2\",\"title_type\":\"1\",\"title_img\":\"\"},\"styles\":{}}]',
        1661757188, 1710989700);
INSERT INTO `la_decorate_page_{tenantSn}`
VALUES (2, {tenantId}, 2, '个人中心',
        '[{\"title\":\"用户信息\",\"name\":\"user-info\",\"disabled\":1,\"content\":{},\"styles\":{}},{\"title\":\"我的服务\",\"name\":\"my-service\",\"content\":{\"style\":1,\"title\":\"我的服务\",\"data\":[{\"image\":\"/resource/image/tenantapi/default/user_collect.png\",\"name\":\"我的收藏\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_setting.png\",\"name\":\"个人设置\",\"link\":{\"path\":\"/pages/user_set/user_set\",\"name\":\"个人设置\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_kefu.png\",\"name\":\"联系客服\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/wallet.png\",\"name\":\"我的点数\",\"link\":{\"path\":\"/packages/pages/user_wallet/user_wallet\",\"name\":\"我的点数\",\"type\":\"shop\"},\"is_show\":\"1\"}],\"enabled\":1},\"styles\":{}},{\"title\":\"个人中心广告图\",\"name\":\"user-banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/user_ad01.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"},{\"image\":\"/resource/image/tenantapi/default/user_ad02.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/customer_service/customer_service\",\"name\":\"联系客服\",\"type\":\"shop\"},\"is_show\":\"1\"}]},\"styles\":{}}]',
        '[{\"title\":\"页面设置\",\"name\":\"page-meta\",\"content\":{\"title\":\"个人中心\",\"bg_type\":\"0\",\"bg_color\":\"\",\"bg_image\":\"\",\"text_color\":\"2\",\"title_type\":\"1\",\"title_img\":\"\"},\"styles\":{}}]',
        1661757188, 1710933097);
INSERT INTO `la_decorate_page_{tenantSn}`
VALUES (3, {tenantId}, 3, '客服设置',
        '[{\"title\":\"客服设置\",\"name\":\"customer-service\",\"content\":{\"title\":\"添加客服二维码\",\"time\":\"早上 9:30 - 19:00\",\"mobile\":\"1888888888\",\"qrcode\":\"/resource/image/common/kefu01.png\",\"remark\":\"长按添加客服或拨打客服热线\"},\"styles\":{}}]',
        '', 1661757188, 1710929953);
INSERT INTO `la_decorate_page_{tenantSn}`
VALUES (4, {tenantId}, 4, 'PC设置',
        '[{\"id\":\"lajcn8d0hzhed\",\"title\":\"首页轮播图\",\"name\":\"pc-banner\",\"content\":{\"enabled\":1,\"data\":[{\"image\":\"/resource/image/tenantapi/default/banner003.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/news/news\",\"name\":\"文章资讯\",\"type\":\"shop\"}},{\"image\":\"/resource/image/tenantapi/default/banner002.png\",\"name\":\"\",\"link\":{\"path\":\"/pages/collection/collection\",\"name\":\"我的收藏\",\"type\":\"shop\"}},{\"image\":\"/resource/image/tenantapi/default/banner001.png\",\"name\":\"\",\"link\":{}}]},\"styles\":{\"position\":\"absolute\",\"left\":\"40\",\"top\":\"75px\",\"width\":\"750px\",\"height\":\"340px\"}}]',
        '', 1661757188, 1710990175);
INSERT INTO `la_decorate_page_{tenantSn}`
VALUES (5, {tenantId}, 5, '系统风格',
        '{\"themeColorId\":3,\"topTextColor\":\"white\",\"navigationBarColor\":\"#A74BFD\",\"themeColor1\":\"#A74BFD\",\"themeColor2\":\"#CB60FF\",\"buttonColor\":\"white\"}',
        '', 1710410915, 1710990415);
COMMIT;

ALTER TABLE `la_tenant_file_{tenantSn}` ADD COLUMN `storage_scope` varchar(20) NOT NULL DEFAULT 'platform' COMMENT '存储作用域';
ALTER TABLE `la_tenant_file_{tenantSn}` ADD COLUMN `storage_engine` varchar(30) NOT NULL DEFAULT 'local' COMMENT '存储引擎';
ALTER TABLE `la_tenant_file_{tenantSn}` ADD COLUMN `storage_domain` varchar(255) NOT NULL DEFAULT '' COMMENT '存储域名';
ALTER TABLE `la_tenant_system_menu_{tenantSn}` ADD COLUMN `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识';
ALTER TABLE `la_tenant_system_menu_{tenantSn}` ADD COLUMN `source` varchar(20) NOT NULL DEFAULT 'core' COMMENT '菜单来源';
ALTER TABLE `la_tenant_system_menu_{tenantSn}` ADD COLUMN `source_menu_key` varchar(120) NOT NULL DEFAULT '' COMMENT '来源菜单key';
ALTER TABLE `la_tenant_system_menu_{tenantSn}` ADD COLUMN `is_core` tinyint NOT NULL DEFAULT 1 COMMENT '是否核心菜单';

INSERT IGNORE INTO `la_tenant_system_menu_{tenantSn}`
(`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9000,{tenantId},0,'M','应用管理','el-icon-Grid',60,'','apps','','','',0,1,0,'','core','core_tenant_app_center',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9001,{tenantId},9000,'C','应用市场','el-icon-Shop',100,'app/market','market','app/market/index','','',0,1,0,'','core','core_tenant_app_market',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9002,{tenantId},9000,'C','我的应用','el-icon-Menu',90,'app/my','my','app/my/index','','',0,0,0,'','core','core_tenant_my_app',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=28,`sort`=10 WHERE `id`=4;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=28,`sort`=20 WHERE `id`=25;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=28,`sort`=90 WHERE `id`=148;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_tenant_storage',`is_core`=1 WHERE `id`=54;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_tenant_storage_setup',`is_core`=1 WHERE `id`=55;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_tenant_storage_change',`is_core`=1 WHERE `id`=56;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_tenant_storage_detail',`is_core`=1 WHERE `id`=57;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_ai_task_tenant',`is_core`=1 WHERE `id`=9016;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `app_code`='',`source`='core',`source_menu_key`='core_ai_task_tenant_detail',`is_core`=1 WHERE `id`=9017;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `name`='系统应用',`paths`='system-default',`component`='',`icon`='el-icon-Setting',`pid`=9000,`sort`=10,`app_code`='system_default',`source`='core',`source_menu_key`='core_tenant_system_default',`is_core`=1 WHERE `id`=158;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `is_show`=0 WHERE `source_menu_key`='core_tenant_my_app';
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=158,`sort`=10 WHERE `id`=159;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=158,`sort`=20 WHERE `id`=70;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=158,`sort`=30 WHERE `id`=101;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `pid`=158,`sort`=40 WHERE `id`=63;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `name`='模板管理',`pid`=96,`sort`=100,`perms`='decorate.template/lists',`paths`='template',`component`='decoration/template/index',`is_show`=1 WHERE `id`=97;
UPDATE `la_tenant_system_menu_{tenantSn}` SET `is_show`=0 WHERE `id` IN (99,142,173,175,176);

ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `template_id` int unsigned NOT NULL DEFAULT 0 COMMENT '模板ID' AFTER `tenant_id`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `terminal` varchar(20) NOT NULL DEFAULT 'mobile' COMMENT '终端 mobile/pc' AFTER `template_id`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `channel` varchar(20) NOT NULL DEFAULT 'common' COMMENT '渠道 common/h5/mp_weixin' AFTER `terminal`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `page_code` varchar(64) NOT NULL DEFAULT '' COMMENT '页面标识' AFTER `channel`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `page_type` varchar(30) NOT NULL DEFAULT 'custom' COMMENT '页面类型' AFTER `page_code`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `route_path` varchar(255) NOT NULL DEFAULT '' COMMENT '页面路径' AFTER `page_type`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `is_home` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否首页' AFTER `route_path`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否系统页面' AFTER `is_home`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态' AFTER `is_system`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `sort` int NOT NULL DEFAULT 0 COMMENT '排序' AFTER `status`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `draft_data` longtext COMMENT '草稿数据' AFTER `meta`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `draft_meta` longtext COMMENT '草稿页面设置' AFTER `draft_data`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `published_data` longtext COMMENT '发布数据' AFTER `draft_meta`;
ALTER TABLE `la_decorate_page_{tenantSn}` ADD COLUMN `published_meta` longtext COMMENT '发布页面设置' AFTER `published_data`;
