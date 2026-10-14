"""Real kernel/auth/DB/worker with disposable external gateway doubles only."""
import json, urllib.request, urllib.parse, subprocess, hashlib, time, pathlib
BASE='http://127.0.0.1:19875'; DB='oem_e2e_virtual_20261014'; CONTAINER='likeadmin-virtual-acceptance'
meta=json.load(urllib.request.urlopen(BASE+'/__virtual/meta')); f=meta['fixtures'][0]; events=[]
def raw(path,data=None,token='',tenant=None):
 headers={'Content-Type':'application/json','token':token,'tenant-id':str(tenant or f['tenant'])}
 req=urllib.request.Request(BASE+path,data=None if data is None else json.dumps(data).encode(),headers=headers)
 return urllib.request.urlopen(req).read()
def api(scope,action,data=None,token='',tenant=None,ok=True):
 value=json.loads(raw('/'+scope+'/'+action,data,token,tenant));events.append({'scope':scope,'action':action.split('?')[0],'code':value.get('code'),'msg':value.get('msg')})
 if ok: assert value['code']==1,(action,value)
 else: assert value['code']!=1,(action,value)
 return value.get('data')
def login(scope,account,terminal):return api(scope,'login/account',{'account':account,'password':meta['password'],'scene':1,'terminal':terminal})['token']
def sql(q):return subprocess.check_output(['docker','exec','likeadmin-oem-db','mysql','-uroot','-N',DB,'-e',q],text=True).strip()
def work():return subprocess.check_output(['docker','exec',CONTAINER,'php','tests/Support/VirtualPayAcceptance/command.php','virtualpay:maintain'],text=True)
def callback(pre,from_):
 signed=json.loads(pre['signData']);body={'Event':'xpay_goods_deliver_notify','OpenId':f['openid'],'OutTradeNo':pre['out_trade_no'],'Env':0,'GoodsInfo':{'ProductId':signed['productId'],'Quantity':1,'OrigPrice':signed['goodsPrice'],'ActualPrice':signed['goodsPrice'],'Attach':signed['attach']}}
 bits=['fixture-token',str(int(time.time())),'acceptance'];signature=hashlib.sha1(''.join(sorted(bits)).encode()).hexdigest();query=urllib.parse.urlencode({'timestamp':bits[1],'nonce':bits[2],'signature':signature})
 endpoint='/wechat/virtual-pay/'+str(f['tenant'])+'/'+f['app']+'/callback?'+query
 reply=json.loads(raw(endpoint,body));assert reply['ErrCode']==0,reply
 return body,endpoint
customer=login('api',f['account'],1);seller=login('tenantapi','virtualseller',1)
config=api('tenantapi','finance.virtual_pay/detail',token=seller);assert config['app_id']==f['app'] and not config.get('app_key')
api('api','package.price/quote?from=recharge&package_id='+str(f['package']),token='',ok=False)
q=api('api','package.price/quote?from=recharge&package_id='+str(f['package']),token=customer);assert q['payable_price']=='9.90'
orders=[]
for from_,pkg,platform,expected in [('recharge',f['package'],'android','9.90'),('membership',f['member'],'ios','19.90')]:
 q=api('api','package.price/quote?'+urllib.parse.urlencode({'from':from_,'package_id':pkg}),token=customer)
 data={'package_id' if from_=='recharge' else 'plan_id':pkg,'cycle':'package','quote_hash':q['quote_hash']}
 order=api('api','recharge/recharge' if from_=='recharge' else 'membership.order/create',data,customer);assert order['order_amount']==expected
 params=urllib.parse.urlencode({'from':from_,'order_id':order['order_id']})
 api('api','pay/payWay?'+params,token=customer,tenant=meta['fixtures'][1]['tenant'],ok=False)
 ways=api('api','pay/payWay?'+params,token=customer);assert ways['lists'][0]['pay_way']==4
 session=api('api','virtualPay/session',{'code':'fixture-user-'+str(f['user'])},customer)
 pre=api('api','virtualPay/prepay',{'from':from_,'order_id':order['order_id'],'session_id':session['session_id'],'platform':platform},customer)
 restored=api('api','virtualPay/prepay',{'from':from_,'order_id':order['order_id'],'session_id':session['session_id'],'platform':platform},customer);assert pre['out_trade_no']==restored['out_trade_no']
 api('api','package.price/cancel',{'from':from_,'order_id':order['order_id']},customer,ok=False)
 req=urllib.request.Request(BASE+'/__virtual/pay',data=urllib.parse.urlencode({'sn':pre['out_trade_no']}).encode());urllib.request.urlopen(req).read()
 if from_=='recharge':
  body,endpoint=callback(pre,from_);assert json.loads(raw(endpoint,body))['ErrCode']==0
 else:
  # No notification: the bounded worker must discover payment and deliver.
  api('tenantapi','finance.virtual_pay/retry',{'id':int(sql("select id from la_virtual_pay_intent where out_trade_no='"+pre['out_trade_no']+"'"))},seller);work()
 status=api('api','pay/payStatus?'+params,token=customer);assert status['pay_status']==1,status
 once=api('api','package.price/quote?'+urllib.parse.urlencode({'from':from_,'package_id':pkg}),token=customer);assert once['reason']=='used' and not once['is_discount']
 orders.append({'from':from_,'order_id':order['order_id'],'sn':pre['out_trade_no'],'params':params})
rows=api('tenantapi','finance.virtual_pay/orders',token=seller)['lists'];assert len(rows)>=2
# Apple developer refund is rejected, Android unused refund is reconciled.
for order in orders:
 intent=int(sql("select id from la_virtual_pay_intent where out_trade_no='"+order['sn']+"'"))
 if order['from']=='membership':api('tenantapi','finance.virtual_pay/refund',{'id':intent},seller,ok=False)
# A later membership grant changes the balance evidence: Android refund must defer, never remove these newer rights.
i=int(sql("select id from la_virtual_pay_intent where out_trade_no='"+orders[0]['sn']+"'"));r=api('tenantapi','finance.virtual_pay/refund',{'id':i},seller);assert r['refund_status']=='manual',r
assert sql('select user_money from la_user where id='+str(f['user']))=='105.00'
assert sql('select count(*) from la_virtual_pay_receipt where tenant_id='+str(f['tenant']))=='2'
# A new regular-price order can be safely refunded when its rights are still untouched.
q=api('api','package.price/quote?from=recharge&package_id='+str(f['package']),token=customer);assert q['payable_price']=='19.00'
regular=api('api','recharge/recharge',{'package_id':f['package'],'quote_hash':q['quote_hash']},customer)
session=api('api','virtualPay/session',{'code':'fixture-user-'+str(f['user'])},customer)
pre=api('api','virtualPay/prepay',{'from':'recharge','order_id':regular['order_id'],'session_id':session['session_id'],'platform':'android'},customer)
urllib.request.urlopen(urllib.request.Request(BASE+'/__virtual/pay',data=urllib.parse.urlencode({'sn':pre['out_trade_no']}).encode())).read()
callback(pre,'recharge');intent=int(sql("select id from la_virtual_pay_intent where out_trade_no='"+pre['out_trade_no']+"'"))
r=api('tenantapi','finance.virtual_pay/refund',{'id':intent},seller);assert r['refund_status']=='queued';work()
assert sql('select refund_status from la_virtual_pay_intent where id='+str(intent))=='processing'
raw('/__virtual/refund',{})
api('tenantapi','finance.virtual_pay/retry',{'id':intent},seller);work()
assert sql('select refund_status from la_virtual_pay_intent where id='+str(intent))=='success'
assert sql('select user_money from la_user where id='+str(f['user']))=='105.00'
status=api('api','pay/payStatus?from=recharge&order_id='+str(regular['order_id']),token=customer);assert status['pay_status']==0 and status['refund_status']=='success'
assert api('api','package.price/quote?from=recharge&package_id='+str(f['package']),token=customer)['reason']=='used'

report={'result':'PASS','orders':orders,'events':events,'real_debit':'SKIPPED','real_refund':'SKIPPED','balance':'105.00','checks':['full authenticated HTTP','unauthenticated and cross-tenant rejected','server quote and amount','same intent restored','unknown cancel retained','signed callback and replay','lost callback worker','lifetime coupon consumed','Apple developer refund denied','stacked rights protected','regular price after discount consumed','refund accepted vs confirmed','safe rights recovered once','refund never restores discount']}
pathlib.Path('/tmp/virtual-pay-ui-checks/http-result.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({k:v for k,v in report.items() if k!='events'},ensure_ascii=False))
