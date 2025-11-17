import json
import os
from pywebpush import webpush, WebPushException

subscription = {
    "endpoint": "https://fcm.googleapis.com/fcm/send/emyyLg3AcPs:APA91bF5fSk7GzXZO3ZaKOzJGgk1JOSQlUu10aWffOEAOXFE0zlRS3HcmqX37FeU_xigmoeDnblH_vmOL0ZOsaguhfP8GJE7eD3fKzffYLbLFkDUgMmIhcVv3tv_dVVQtzpcFGttdNfV",
    "expirationTime": None,
    "keys": {
        "p256dh": "BOsW5cd7tnxsxTlvlxMu4cW6umRW0xxf_7W5ZM70f4bBqLBVvoGA-ItEk8xKO22SeYEmXdk_vCnbDPdkSsncU5w",
        "auth": "UrMulK48A6DvlxiZxL8oFg",
    },
}

# 1) Put your real keys here or pull from env
VAPID_PRIVATE_KEY = os.getenv("NOTIFICATIONS_VAPID_PRIVATE_KEY", "vq-VFxjwJbMmq58QpKTeabv91ATEg8-wEHXLKKHAlD0")
VAPID_EMAIL = os.getenv("NOTIFICATIONS_VAPID_EMAIL", "mailto:admin@movana.me")

print("Using VAPID private:", VAPID_PRIVATE_KEY)

if not VAPID_PRIVATE_KEY or VAPID_PRIVATE_KEY == "YOUR_VAPID_PRIVATE_KEY_HERE":
    raise SystemExit("Set NOTIFICATIONS_VAPID_PRIVATE_KEY env or hardcode it in this script.")

try:
    res = webpush(
        subscription_info=subscription,
        data=json.dumps({
            "title": "Direct test",
            "body": "This is a direct test push",
            "icon": None,
            "url": "https://movana.me",
        }),
        vapid_private_key=VAPID_PRIVATE_KEY,
        vapid_claims={"sub": VAPID_EMAIL},
    )
    print("Sent OK, HTTP status:", res.status_code)
    print("Response headers:", dict(res.headers))
    print("Response body:", res.content)
except WebPushException as exc:
    print("WebPushException:", repr(exc))
    if exc.response is not None:
        print("HTTP status:", exc.response.status_code)
        print("Response body:", exc.response.content)
