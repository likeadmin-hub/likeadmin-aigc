# 产品宣传视频前端入口

Frontend source lives in:

- `tenant/src/apps/aigc_product_promo_video`
- `tenant/src/types/apps/aigc_product_promo_video`
- `pc/apps/aigc_product_promo_video`
- `uniapp/src/apps/aigc_product_promo_video`

`POST app.aigc_product_promo_video.prompt/write` requires `source_image`, the
uploaded product image URI belonging to the current tenant and user. Both PC and
UniApp disable AI writing until an image is selected. PC uploads a local preview
before calling this API. The service validates the stored file, supplies image
content to the existing vision-capable text runtime, and retains the existing
`prompt`, `charge_points`, and `model_code` response fields and billing path.
`prompt/optimize` remains a text-only action and does not require an image.

