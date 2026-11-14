<?php
namespace app\common\service\database;

/** Request-local tenant/storage metadata while formatting a list response. */
final class ListingReadScope
{
    private array $values=[];

    public static function run(callable $read): mixed
    {
        if(!function_exists('request'))return $read();
        $request=request();
        if($request->listingReadScope instanceof self)return $read();
        $request->listingReadScope=new self();
        try{return $read();}finally{$request->listingReadScope=null;}
    }
    public static function remember(string $key,callable $read): array
    {
        $scope=function_exists('request')?request()->listingReadScope:null;
        if(!$scope instanceof self)return $read();
        if(!array_key_exists($key,$scope->values))$scope->values[$key]=$read();
        return $scope->values[$key];
    }
    public static function forget(string $key): void
    {
        $scope=function_exists('request')?request()->listingReadScope:null;
        if($scope instanceof self)unset($scope->values[$key]);
    }
}
