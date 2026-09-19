<?php
/**
 * Shared locale strings for EPIC customer-facing emails.
 *
 * The storefront serves seven locales (en, vi, ru, hi, zh, ko, ja) but this
 * project ships no compiled gettext .mo files, so each customer email template
 * looks up its body copy here, keyed by the storefront locale captured when the
 * customer submitted the form (newsletter / sample / wholesale) or stored on
 * the order. Staff-facing admin emails stay Vietnamese on purpose — this file is
 * only for mail that reaches the customer.
 *
 * `epic_email_str()` resolves a region tag ("zh-CN") to its base language
 * ("zh") and falls back to English for an unknown or untranslated locale, so a
 * missing string never renders blank. Templates keep doing their own
 * esc_html()/wp_kses_post() — this file returns plain text only.
 *
 * Guarded with function_exists() because a standalone plugin and the bundled
 * suite module can both be present during the migration window.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'epic_email_str' ) ) {
	/**
	 * @param string $key    String key (see the map below).
	 * @param string $locale Storefront locale code, e.g. "en", "zh-CN".
	 * @return string Localized text, falling back to English, then ''.
	 */
	function epic_email_str( $key, $locale ) {
		static $strings = null;
		if ( null === $strings ) {
			$strings = array(
				// --- Newsletter: subscription confirmation ------------------
				'nl_thanks' => array(
					'en' => 'Thanks for subscribing to the EPIC Roastery newsletter.',
					'vi' => 'Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery.',
					'ru' => 'Спасибо за подписку на рассылку EPIC Roastery.',
					'hi' => 'EPIC Roastery न्यूज़लेटर की सदस्यता के लिए धन्यवाद।',
					'zh' => '感谢您订阅 EPIC Roastery 电子报。',
					'ko' => 'EPIC Roastery 뉴스레터를 구독해 주셔서 감사합니다.',
					'ja' => 'EPIC Roastery のニュースレターをご購読いただきありがとうございます。',
				),
				'nl_body' => array(
					'en' => "You're on the list — we'll send you new bean releases, roastery updates and any future promotion coupons as soon as they're out.",
					'vi' => 'Bạn đã vào danh sách — chúng tôi sẽ gửi cho bạn cà phê mới ra mắt, cập nhật từ xưởng rang và mã giảm giá trong tương lai ngay khi có.',
					'ru' => 'Вы в списке — мы будем присылать новые релизы зерна, новости обжарочного цеха и будущие промокоды сразу после их выхода.',
					'hi' => 'आप सूची में हैं — नई बीन रिलीज़, रोस्ट्री अपडेट और भविष्य के प्रोमो कूपन जारी होते ही हम आपको भेजेंगे।',
					'zh' => '您已加入名单——新品咖啡豆、烘焙坊动态以及日后的优惠券，一经推出我们会立即发送给您。',
					'ko' => '구독 목록에 등록되었습니다. 새 원두 출시, 로스터리 소식, 향후 프로모션 쿠폰이 나오는 대로 보내드리겠습니다.',
					'ja' => 'ご登録が完了しました。新しい豆のリリース、ロースタリーの最新情報、今後のクーポンを随時お届けします。',
				),
				'nl_unsub' => array(
					'en' => "If this wasn't you, or you'd like to unsubscribe at any time, just reply to this email and we'll take care of it.",
					'vi' => 'Nếu không phải bạn đăng ký, hoặc bạn muốn hủy nhận tin bất cứ lúc nào, chỉ cần trả lời email này và chúng tôi sẽ xử lý ngay.',
					'ru' => 'Если это были не вы или вы хотите отписаться в любой момент, просто ответьте на это письмо, и мы всё сделаем.',
					'hi' => 'यदि यह आपने नहीं किया, या आप किसी भी समय सदस्यता रद्द करना चाहते हैं, तो बस इस ईमेल का जवाब दें — हम संभाल लेंगे।',
					'zh' => '如果这不是您本人操作，或您想随时退订，只需回复本邮件，我们会为您处理。',
					'ko' => '본인이 아니거나 언제든 구독을 해지하고 싶으시면 이 이메일에 답장만 해 주세요. 저희가 처리해 드립니다.',
					'ja' => '心当たりがない場合や配信停止をご希望の場合は、このメールに返信いただければ対応いたします。',
				),
				// --- Newsletter: bulk broadcast footer ----------------------
				'bc_unsub' => array(
					'en' => "You're receiving this because you subscribed to the EPIC Roastery newsletter. To unsubscribe, just reply to this email and we'll take care of it.",
					'vi' => 'Bạn nhận được email này vì đã đăng ký nhận tin từ EPIC Roastery. Để hủy đăng ký, chỉ cần trả lời email này và chúng tôi sẽ xử lý ngay.',
					'ru' => 'Вы получили это письмо, потому что подписались на рассылку EPIC Roastery. Чтобы отписаться, просто ответьте на это письмо.',
					'hi' => 'आपको यह ईमेल इसलिए मिला क्योंकि आपने EPIC Roastery न्यूज़लेटर की सदस्यता ली थी। सदस्यता रद्द करने के लिए इस ईमेल का जवाब दें।',
					'zh' => '您收到本邮件是因为您订阅了 EPIC Roastery 电子报。如需退订，只需回复本邮件即可。',
					'ko' => 'EPIC Roastery 뉴스레터를 구독하셔서 이 메일을 받으셨습니다. 구독 해지는 이 메일에 답장해 주시면 처리해 드립니다.',
					'ja' => 'EPIC Roastery のニュースレターをご購読いただいたためお送りしています。配信停止はこのメールに返信してください。',
				),
				// --- Sample request confirmation ---------------------------
				'sp_hi' => array(
					'en' => 'Hi %s,',
					'vi' => 'Chào %s,',
					'ru' => 'Здравствуйте, %s!',
					'hi' => 'नमस्ते %s,',
					'zh' => '%s 您好，',
					'ko' => '%s님, 안녕하세요.',
					'ja' => '%s 様',
				),
				'sp_thanks' => array(
					'en' => "Thank you for requesting a free sample from EPIC Coffee Roaster. We've received your request and will send it out to you shortly.",
					'vi' => 'Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí từ EPIC Coffee Roaster. Chúng tôi đã nhận được yêu cầu và sẽ gửi mẫu đến bạn trong thời gian sớm nhất.',
					'ru' => 'Спасибо за заявку на бесплатный образец кофе от EPIC Coffee Roaster. Мы получили её и вскоре отправим образец вам.',
					'hi' => 'EPIC Coffee Roaster से निःशुल्क सैंपल का अनुरोध करने के लिए धन्यवाद। हमें आपका अनुरोध प्राप्त हो गया है और हम जल्द ही इसे भेज देंगे।',
					'zh' => '感谢您向 EPIC Coffee Roaster 申请免费样品。我们已收到您的申请，将尽快为您寄出。',
					'ko' => 'EPIC Coffee Roaster의 무료 샘플을 신청해 주셔서 감사합니다. 요청을 접수했으며 곧 발송해 드리겠습니다.',
					'ja' => 'EPIC Coffee Roaster の無料サンプルをお申し込みいただきありがとうございます。ご依頼を承りましたので、まもなく発送いたします。',
				),
				'sp_deliver' => array(
					'en' => 'Delivering to:',
					'vi' => 'Địa chỉ nhận mẫu:',
					'ru' => 'Адрес доставки:',
					'hi' => 'डिलीवरी का पता:',
					'zh' => '收货地址：',
					'ko' => '배송 주소:',
					'ja' => 'お届け先：',
				),
				'sp_taste' => array(
					'en' => 'Favourite taste:',
					'vi' => 'Khẩu vị bạn chọn:',
					'ru' => 'Выбранный вкус:',
					'hi' => 'पसंदीदा स्वाद:',
					'zh' => '偏好风味：',
					'ko' => '선호하는 맛:',
					'ja' => 'お好みの味わい：',
				),
				'sp_brew' => array(
					'en' => 'Brew style:',
					'vi' => 'Cách pha:',
					'ru' => 'Способ приготовления:',
					'hi' => 'ब्रू शैली:',
					'zh' => '冲煮方式：',
					'ko' => '추출 방식:',
					'ja' => '抽出方法：',
				),
				'sp_contact' => array(
					'en' => "Our team will contact you by phone if we need to confirm anything about the delivery. If any of the details above look wrong, just reply to this email and we'll fix it.",
					'vi' => 'Nếu cần xác nhận thông tin giao hàng, đội ngũ của chúng tôi sẽ liên hệ với bạn qua số điện thoại đã cung cấp. Nếu có thông tin nào chưa đúng, bạn chỉ cần trả lời email này và chúng tôi sẽ điều chỉnh.',
					'ru' => 'При необходимости уточнить детали доставки наша команда свяжется с вами по телефону. Если что-то выше указано неверно, просто ответьте на это письмо, и мы исправим.',
					'hi' => 'डिलीवरी से जुड़ी किसी बात की पुष्टि करनी हो तो हमारी टीम आपको फ़ोन पर संपर्क करेगी। ऊपर दी गई कोई जानकारी गलत लगे तो इस ईमेल का जवाब दें, हम ठीक कर देंगे।',
					'zh' => '如需确认配送信息，我们的团队会致电与您联系。若以上信息有误，只需回复本邮件，我们会为您更正。',
					'ko' => '배송 관련 확인이 필요하면 저희 팀이 전화로 연락드립니다. 위 내용 중 잘못된 부분이 있으면 이 메일에 답장해 주시면 수정해 드리겠습니다.',
					'ja' => '配送について確認が必要な場合は、担当者がお電話でご連絡します。上記の内容に誤りがあれば、このメールに返信いただければ修正いたします。',
				),
				// --- Wholesale order confirmation ---------------------------
				'ws_hi' => array(
					'en' => 'Hi %1$s, we have received your wholesale order %2$s.',
					'vi' => 'Chào %1$s, chúng tôi đã nhận được đơn hàng sỉ %2$s của bạn.',
					'ru' => 'Здравствуйте, %1$s! Мы получили ваш оптовый заказ %2$s.',
					'hi' => 'नमस्ते %1$s, हमें आपका थोक ऑर्डर %2$s प्राप्त हो गया है।',
					'zh' => '%1$s 您好，我们已收到您的批发订单 %2$s。',
					'ko' => '%1$s님, 도매 주문 %2$s을(를) 접수했습니다.',
					'ja' => '%1$s 様、卸売りご注文 %2$s を承りました。',
				),
				'ws_product' => array( 'en' => 'Product', 'vi' => 'Sản phẩm', 'ru' => 'Товар', 'hi' => 'उत्पाद', 'zh' => '产品', 'ko' => '제품', 'ja' => '商品' ),
				'ws_sku' => array( 'en' => 'SKU', 'vi' => 'SKU', 'ru' => 'Артикул', 'hi' => 'SKU', 'zh' => '货号', 'ko' => 'SKU', 'ja' => 'SKU' ),
				'ws_qty' => array( 'en' => 'Quantity', 'vi' => 'Số lượng', 'ru' => 'Количество', 'hi' => 'मात्रा', 'zh' => '数量', 'ko' => '수량', 'ja' => '数量' ),
				'ws_unit' => array( 'en' => 'Unit price', 'vi' => 'Đơn giá', 'ru' => 'Цена за единицу', 'hi' => 'इकाई मूल्य', 'zh' => '单价', 'ko' => '단가', 'ja' => '単価' ),
				'ws_line_total' => array( 'en' => 'Line total', 'vi' => 'Thành tiền', 'ru' => 'Сумма', 'hi' => 'कुल राशि', 'zh' => '小计', 'ko' => '합계', 'ja' => '小計' ),
				'ws_total' => array( 'en' => 'Total', 'vi' => 'Tổng', 'ru' => 'Итого', 'hi' => 'कुल', 'zh' => '合计', 'ko' => '합계', 'ja' => '合計' ),
				'ws_price_pending' => array(
					'en' => 'Prices will be confirmed by the EPIC team and shared with you once the order is approved.',
					'vi' => 'Giá của các sản phẩm sẽ được đội ngũ EPIC xác nhận và thông báo với bạn sau khi đơn hàng được duyệt.',
					'ru' => 'Цены подтвердит команда EPIC и сообщит вам после одобрения заказа.',
					'hi' => 'ऑर्डर स्वीकृत होने पर EPIC टीम कीमतें तय करके आपको बताएगी।',
					'zh' => '订单获批后，EPIC 团队将确认价格并告知您。',
					'ko' => '주문이 승인되면 EPIC 팀이 가격을 확정해 알려드립니다.',
					'ja' => 'ご注文の承認後、EPIC チームが価格を確定してご連絡します。',
				),
				'ws_note' => array( 'en' => 'Your note', 'vi' => 'Ghi chú của bạn', 'ru' => 'Ваша заметка', 'hi' => 'आपका नोट', 'zh' => '您的备注', 'ko' => '요청 사항', 'ja' => 'ご要望' ),
				'ws_level' => array( 'en' => 'Pricing level', 'vi' => 'Mức giá', 'ru' => 'Ценовой уровень', 'hi' => 'मूल्य स्तर', 'zh' => '价格级别', 'ko' => '가격 등급', 'ja' => '価格ティア' ),
				'ws_confirm' => array(
					'en' => "We'll confirm your order and get back to you as soon as possible.",
					'vi' => 'Chúng tôi sẽ xác nhận đơn hàng và liên hệ với bạn sớm nhất có thể.',
					'ru' => 'Мы подтвердим заказ и свяжемся с вами в ближайшее время.',
					'hi' => 'हम आपके ऑर्डर की पुष्टि करके जल्द से जल्द आपसे संपर्क करेंगे।',
					'zh' => '我们将确认您的订单，并尽快与您联系。',
					'ko' => '주문을 확인한 후 최대한 빨리 연락드리겠습니다.',
					'ja' => 'ご注文を確認のうえ、できるだけ早くご連絡いたします。',
				),
				// --- Order emails -------------------------------------------
				'or_hi' => array(
					'en' => 'Hi %s,',
					'vi' => 'Chào %s,',
					'ru' => 'Здравствуйте, %s!',
					'hi' => 'नमस्ते %s,',
					'zh' => '%s 您好，',
					'ko' => '%s님, 안녕하세요.',
					'ja' => '%s 様',
				),
				'or_thanks' => array(
					'en' => "Thank you for ordering from EPIC Roastery. We've received your order and are getting it ready.",
					'vi' => 'Cảm ơn bạn đã đặt hàng tại EPIC Roastery. Chúng tôi đã nhận được đơn hàng của bạn và đang chuẩn bị.',
					'ru' => 'Спасибо за заказ в EPIC Roastery. Мы получили его и уже готовим.',
					'hi' => 'EPIC Roastery से ऑर्डर करने के लिए धन्यवाद। हमें आपका ऑर्डर मिल गया है और हम उसे तैयार कर रहे हैं।',
					'zh' => '感谢您在 EPIC Roastery 下单。我们已收到您的订单，正在为您准备。',
					'ko' => 'EPIC Roastery에서 주문해 주셔서 감사합니다. 주문을 접수했으며 준비 중입니다.',
					'ja' => 'EPIC Roastery をご利用いただきありがとうございます。ご注文を承り、準備を進めております。',
				),
				'or_ship_note' => array(
					'en' => "We'll send another email when your order ships, with a tracking code so you can follow it.",
					'vi' => 'Chúng tôi sẽ gửi thêm email khi đơn hàng được giao cho đơn vị vận chuyển, kèm mã vận đơn để bạn theo dõi.',
					'ru' => 'Мы отправим ещё одно письмо, когда заказ будет передан в доставку, с трек-кодом, чтобы вы могли отслеживать его.',
					'hi' => 'जब आपका ऑर्डर भेजा जाएगा तो हम ट्रैकिंग कोड के साथ एक और ईमेल भेजेंगे ताकि आप उसे ट्रैक कर सकें।',
					'zh' => '您的订单发货时，我们会再发一封邮件，并附上配送单号，方便您跟踪。',
					'ko' => '주문이 발송되면 추적할 수 있도록 운송장 번호와 함께 다른 이메일을 보내드립니다.',
					'ja' => 'ご注文が発送された際は、追跡できるよう追跡番号を記載した別のメールをお送りします。',
				),
				// --- Order shipped ------------------------------------------
				'os_shipped' => array(
					'en' => 'Your order #%s has been handed to GHN (Giao Hàng Nhanh).',
					'vi' => 'Đơn hàng #%s của bạn đã được bàn giao cho đơn vị vận chuyển GHN (Giao Hàng Nhanh).',
					'ru' => 'Ваш заказ #%s передан службе доставки GHN (Giao Hàng Nhanh).',
					'hi' => 'आपका ऑर्डर #%s GHN (Giao Hàng Nhanh) को सौंप दिया गया है।',
					'zh' => '您的订单 #%s 已交给 GHN（Giao Hàng Nhanh）配送。',
					'ko' => '주문 #%s이(가) GHN(Giao Hàng Nhanh)에 인계되었습니다.',
					'ja' => 'ご注文 #%s を GHN（Giao Hàng Nhanh）に引き渡しました。',
				),
				'os_tracking_label' => array( 'en' => 'GHN tracking code', 'vi' => 'Mã vận đơn GHN', 'ru' => 'Трек-код GHN', 'hi' => 'GHN ट्रैकिंग कोड', 'zh' => 'GHN 配送单号', 'ko' => 'GHN 운송장 번호', 'ja' => 'GHN 追跡番号' ),
				'os_track' => array( 'en' => 'Track your order', 'vi' => 'Theo dõi đơn hàng', 'ru' => 'Отследить заказ', 'hi' => 'अपना ऑर्डर ट्रैक करें', 'zh' => '跟踪您的订单', 'ko' => '주문 추적', 'ja' => '注文を追跡' ),
				'os_track_hint' => array(
					'en' => "If the link above doesn't open the right order, go to donhang.ghn.vn and enter the tracking code above.",
					'vi' => 'Nếu liên kết trên không mở đúng đơn hàng, hãy truy cập donhang.ghn.vn và nhập mã vận đơn ở trên.',
					'ru' => 'Если ссылка выше не открывает нужный заказ, зайдите на donhang.ghn.vn и введите трек-код выше.',
					'hi' => 'यदि ऊपर का लिंक सही ऑर्डर नहीं खोलता, तो donhang.ghn.vn पर जाएँ और ऊपर दिया ट्रैकिंग कोड डालें।',
					'zh' => '如果上方链接未打开正确的订单，请前往 donhang.ghn.vn 并输入上方的配送单号。',
					'ko' => '위 링크가 올바른 주문을 열지 않으면 donhang.ghn.vn에서 위 운송장 번호를 입력하세요.',
					'ja' => '上のリンクで正しい注文が開かない場合は、donhang.ghn.vn にアクセスし、上の追跡番号を入力してください。',
				),
				'os_eta' => array( 'en' => 'Estimated delivery:', 'vi' => 'Dự kiến giao hàng:', 'ru' => 'Ожидаемая доставка:', 'hi' => 'अनुमानित डिलीवरी:', 'zh' => '预计送达：', 'ko' => '예상 배송:', 'ja' => 'お届け予定：' ),
				'os_cod' => array( 'en' => 'Cash on delivery (COD) amount:', 'vi' => 'Số tiền thanh toán khi nhận hàng (COD):', 'ru' => 'Сумма оплаты при получении (COD):', 'hi' => 'डिलीवरी पर नकद (COD) राशि:', 'zh' => '货到付款（COD）金额：', 'ko' => '착불(COD) 금액:', 'ja' => '代金引換（COD）金額：' ),
				// --- Branding header/footer --------------------------------
				'brand_tagline' => array(
					'en' => 'Doing better each day · Specialty coffee roasted in Sài Gòn since 2014',
					'vi' => 'Mỗi ngày tốt hơn · Cà phê đặc sản rang tại Sài Gòn từ 2014',
					'ru' => 'С каждым днём лучше · Спешелти-кофе, обжаренный в Сайгоне с 2014 года',
					'hi' => 'हर दिन बेहतर · 2014 से साइगॉन में भुनी स्पेशल्टी कॉफ़ी',
					'zh' => '日复一日，做得更好 · 自 2014 年西贡烘焙的精品咖啡',
					'ko' => '매일 더 나아지게 · 2014년부터 사이공에서 로스팅한 스페셜티 커피',
					'ja' => '毎日、より良く · 2014年からサイゴンで焙煎するスペシャルティコーヒー',
				),
				'label_cafe' => array( 'en' => 'Café', 'vi' => 'Quán', 'ru' => 'Кафе', 'hi' => 'कैफ़े', 'zh' => '咖啡馆', 'ko' => '카페', 'ja' => 'カフェ' ),
				'label_roastery' => array( 'en' => 'Roastery', 'vi' => 'Xưởng rang', 'ru' => 'Обжарочный цех', 'hi' => 'रोस्ट्री', 'zh' => '烘焙坊', 'ko' => '로스터리', 'ja' => 'ロースタリー' ),
				'label_hotline' => array( 'en' => 'Hotline', 'vi' => 'Hotline', 'ru' => 'Горячая линия', 'hi' => 'हॉटलाइन', 'zh' => '热线', 'ko' => '핫라인', 'ja' => 'ホットライン' ),
				'label_email' => array( 'en' => 'Email', 'vi' => 'Email', 'ru' => 'Email', 'hi' => 'ईमेल', 'zh' => '邮箱', 'ko' => '이메일', 'ja' => 'メール' ),
				'brand_hours' => array(
					'en' => 'Open daily 7:00–22:30',
					'vi' => 'Mở cửa hằng ngày 7:00–22:30',
					'ru' => 'Ежедневно 7:00–22:30',
					'hi' => 'रोज़ 7:00–22:30 खुला',
					'zh' => '每天 7:00–22:30 营业',
					'ko' => '매일 7:00–22:30 영업',
					'ja' => '毎日 7:00–22:30 営業',
				),
				'brand_rights' => array(
					'en' => 'All rights reserved.',
					'vi' => 'Bảo lưu mọi quyền.',
					'ru' => 'Все права защищены.',
					'hi' => 'सर्वाधिकार सुरक्षित।',
					'zh' => '版权所有。',
					'ko' => '모든 권리 보유.',
					'ja' => '無断転載を禁じます。',
				),
				// --- Headings ----------------------------------------------
				'heading_newsletter' => array(
					'en' => 'Thanks for subscribing to the EPIC Roastery newsletter!',
					'vi' => 'Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery!',
					'ru' => 'Спасибо за подписку на рассылку EPIC Roastery!',
					'hi' => 'EPIC Roastery न्यूज़लेटर की सदस्यता के लिए धन्यवाद!',
					'zh' => '感谢您订阅 EPIC Roastery 电子报！',
					'ko' => 'EPIC Roastery 뉴스레터를 구독해 주셔서 감사합니다!',
					'ja' => 'EPIC Roastery のニュースレターをご購読いただきありがとうございます！',
				),
				'heading_sample' => array(
					'en' => 'Thank you for requesting a free coffee sample!',
					'vi' => 'Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí!',
					'ru' => 'Спасибо за заявку на бесплатный образец кофе!',
					'hi' => 'मुफ़्त कॉफ़ी सैंपल का अनुरोध करने के लिए धन्यवाद!',
					'zh' => '感谢您申请免费咖啡样品！',
					'ko' => '무료 커피 샘플을 신청해 주셔서 감사합니다!',
					'ja' => '無料コーヒーサンプルをお申し込みいただきありがとうございます！',
				),
				'heading_wholesale' => array(
					'en' => "We've received your wholesale order",
					'vi' => 'Đã nhận đơn hàng sỉ của bạn',
					'ru' => 'Мы получили ваш оптовый заказ',
					'hi' => 'हमें आपका थोक ऑर्डर प्राप्त हो गया है',
					'zh' => '我们已收到您的批发订单',
					'ko' => '도매 주문을 접수했습니다',
					'ja' => '卸売りご注文を承りました',
				),
				'heading_order_created' => array(
					'en' => 'Thank you for your order at EPIC Roastery!',
					'vi' => 'Cảm ơn bạn đã đặt hàng tại EPIC Roastery!',
					'ru' => 'Спасибо за заказ в EPIC Roastery!',
					'hi' => 'EPIC Roastery से ऑर्डर करने के लिए धन्यवाद!',
					'zh' => '感谢您在 EPIC Roastery 下单！',
					'ko' => 'EPIC Roastery에서 주문해 주셔서 감사합니다!',
					'ja' => 'EPIC Roastery をご利用いただきありがとうございます！',
				),
				'heading_order_shipped' => array(
					'en' => 'Your order is on its way',
					'vi' => 'Đơn hàng của bạn đang trên đường tới',
					'ru' => 'Ваш заказ в пути',
					'hi' => 'आपका ऑर्डर रास्ते में है',
					'zh' => '您的订单已在路上',
					'ko' => '주문이 배송 중입니다',
					'ja' => 'ご注文を発送しました',
				),
				// --- Subjects (placeholders preserved) ----------------------
				'subject_newsletter' => array(
					'en' => '[{site_title}] Thanks for subscribing',
					'vi' => '[{site_title}] Cảm ơn bạn đã đăng ký nhận tin',
					'ru' => '[{site_title}] Спасибо за подписку',
					'hi' => '[{site_title}] सदस्यता के लिए धन्यवाद',
					'zh' => '[{site_title}] 感谢订阅',
					'ko' => '[{site_title}] 구독해 주셔서 감사합니다',
					'ja' => '[{site_title}] ご購読ありがとうございます',
				),
				'subject_sample' => array(
					'en' => '[{site_title}] Thank you for your free-sample request',
					'vi' => '[{site_title}] Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí',
					'ru' => '[{site_title}] Спасибо за заявку на бесплатный образец',
					'hi' => '[{site_title}] मुफ़्त सैंपल अनुरोध के लिए धन्यवाद',
					'zh' => '[{site_title}] 感谢申请免费样品',
					'ko' => '[{site_title}] 무료 샘플 신청 감사합니다',
					'ja' => '[{site_title}] 無料サンプルをお申し込みいただきありがとうございます',
				),
				'subject_wholesale' => array(
					'en' => '[{site_title}] Wholesale order confirmed {order_number}',
					'vi' => '[{site_title}] Xác nhận đơn hàng sỉ {order_number}',
					'ru' => '[{site_title}] Оптовый заказ подтверждён {order_number}',
					'hi' => '[{site_title}] थोक ऑर्डर की पुष्टि {order_number}',
					'zh' => '[{site_title}] 批发订单已确认 {order_number}',
					'ko' => '[{site_title}] 도매 주문 확인 {order_number}',
					'ja' => '[{site_title}] 卸売りご注文の確認 {order_number}',
				),
				'subject_order_created' => array(
					'en' => '[{site_title}] Order #{order_number} confirmed',
					'vi' => '[{site_title}] Xác nhận đơn hàng #{order_number}',
					'ru' => '[{site_title}] Заказ №{order_number} подтверждён',
					'hi' => '[{site_title}] ऑर्डर #{order_number} की पुष्टि',
					'zh' => '[{site_title}] 订单 #{order_number} 已确认',
					'ko' => '[{site_title}] 주문 #{order_number} 확인',
					'ja' => '[{site_title}] ご注文 #{order_number} を承りました',
				),
				'subject_order_shipped' => array(
					'en' => '[{site_title}] Order #{order_number} has shipped',
					'vi' => '[{site_title}] Đơn hàng #{order_number} đã giao cho vận chuyển',
					'ru' => '[{site_title}] Заказ №{order_number} отправлен',
					'hi' => '[{site_title}] ऑर्डर #{order_number} भेज दिया गया',
					'zh' => '[{site_title}] 订单 #{order_number} 已发货',
					'ko' => '[{site_title}] 주문 #{order_number}이(가) 발송되었습니다',
					'ja' => '[{site_title}] ご注文 #{order_number} を発送しました',
				),
				// --- Admin/staff notifications (localized to the submitter) --
				'label_phone' => array( 'en' => 'Phone number', 'vi' => 'Số điện thoại', 'ru' => 'Телефон', 'hi' => 'फ़ोन', 'zh' => '电话', 'ko' => '전화', 'ja' => '電話' ),
				'label_name' => array( 'en' => 'Name', 'vi' => 'Họ tên', 'ru' => 'Имя', 'hi' => 'नाम', 'zh' => '姓名', 'ko' => '이름', 'ja' => 'お名前' ),
				'label_subscribed_at' => array( 'en' => 'Subscribed at', 'vi' => 'Thời gian đăng ký', 'ru' => 'Время подписки', 'hi' => 'सदस्यता का समय', 'zh' => '订阅时间', 'ko' => '구독 시간', 'ja' => '購読日時' ),
				'label_submitted_at' => array( 'en' => 'Submitted at', 'vi' => 'Thời gian gửi', 'ru' => 'Время отправки', 'hi' => 'भेजने का समय', 'zh' => '提交时间', 'ko' => '제출 시간', 'ja' => '送信日時' ),
				'nl_admin_heading' => array( 'en' => 'New newsletter subscriber', 'vi' => 'Người đăng ký nhận tin mới', 'ru' => 'Новый подписчик рассылки', 'hi' => 'नया न्यूज़लेटर सब्सक्राइबर', 'zh' => '新的电子报订阅者', 'ko' => '새 뉴스레터 구독자', 'ja' => '新しいニュースレター購読者' ),
				'nl_admin_subject' => array(
					'en' => '[{site_title}] New newsletter subscriber — {subscriber_email}',
					'vi' => '[{site_title}] Đăng ký nhận tin mới — {subscriber_email}',
					'ru' => '[{site_title}] Новый подписчик рассылки — {subscriber_email}',
					'hi' => '[{site_title}] नया न्यूज़लेटर सब्सक्राइबर — {subscriber_email}',
					'zh' => '[{site_title}] 新的电子报订阅者 — {subscriber_email}',
					'ko' => '[{site_title}] 새 뉴스레터 구독자 — {subscriber_email}',
					'ja' => '[{site_title}] 新しいニュースレター購読者 — {subscriber_email}',
				),
				'nl_admin_intro' => array(
					'en' => 'Someone new subscribed to the newsletter from the website.',
					'vi' => 'Có một người mới đăng ký nhận tin từ website.',
					'ru' => 'Новый подписчик оформил подписку на рассылку на сайте.',
					'hi' => 'वेबसाइट से किसी नए व्यक्ति ने न्यूज़लेटर की सदस्यता ली है।',
					'zh' => '有新的访客通过网站订阅了电子报。',
					'ko' => '웹사이트에서 새 구독자가 뉴스레터를 구독했습니다.',
					'ja' => 'ウェブサイトから新しい読者がニュースレターを購読しました。',
				),
				'nl_admin_list_note' => array(
					'en' => 'The full subscriber list is in WooCommerce → Newsletter Subscribers.',
					'vi' => 'Danh sách người đăng ký đầy đủ nằm trong WooCommerce → Newsletter Subscribers.',
					'ru' => 'Полный список подписчиков — в WooCommerce → Newsletter Subscribers.',
					'hi' => 'पूरी सदस्य सूची WooCommerce → Newsletter Subscribers में है।',
					'zh' => '完整订阅名单在 WooCommerce → Newsletter Subscribers 中。',
					'ko' => '전체 구독자 목록은 WooCommerce → Newsletter Subscribers에 있습니다.',
					'ja' => '購読者一覧は WooCommerce → Newsletter Subscribers にあります。',
				),
				'sp_admin_heading' => array( 'en' => 'New free-sample request', 'vi' => 'Yêu cầu nhận mẫu cà phê miễn phí', 'ru' => 'Новая заявка на бесплатный образец', 'hi' => 'नया मुफ़्त सैंपल अनुरोध', 'zh' => '新的免费样品申请', 'ko' => '새 무료 샘플 신청', 'ja' => '新しい無料サンプルの申し込み' ),
				'sp_admin_subject' => array(
					'en' => '[{site_title}] New free-sample request — {name}',
					'vi' => '[{site_title}] Yêu cầu nhận mẫu miễn phí — {name}',
					'ru' => '[{site_title}] Заявка на бесплатный образец — {name}',
					'hi' => '[{site_title}] मुफ़्त सैंपल अनुरोध — {name}',
					'zh' => '[{site_title}] 免费样品申请 — {name}',
					'ko' => '[{site_title}] 무료 샘플 신청 — {name}',
					'ja' => '[{site_title}] 無料サンプルのお申し込み — {name}',
				),
				'sp_admin_intro' => array(
					'en' => 'A new free-sample request was submitted from the website.',
					'vi' => 'Có một yêu cầu nhận mẫu cà phê miễn phí mới được gửi từ website.',
					'ru' => 'С сайта поступила новая заявка на бесплатный образец кофе.',
					'hi' => 'वेबसाइट से मुफ़्त सैंपल का नया अनुरोध भेजा गया है।',
					'zh' => '网站收到了一份新的免费样品申请。',
					'ko' => '웹사이트에서 새 무료 샘플 신청이 접수되었습니다.',
					'ja' => 'ウェブサイトから新しい無料サンプルの申し込みがありました。',
				),
				'sp_admin_address_label' => array( 'en' => 'Sample delivery address', 'vi' => 'Địa chỉ nhận mẫu', 'ru' => 'Адрес доставки образца', 'hi' => 'सैंपल डिलीवरी पता', 'zh' => '样品收货地址', 'ko' => '샘플 배송 주소', 'ja' => 'サンプルお届け先' ),
				'sp_admin_taste_label' => array( 'en' => 'Favourite taste', 'vi' => 'Khẩu vị yêu thích', 'ru' => 'Любимый вкус', 'hi' => 'पसंदीदा स्वाद', 'zh' => '偏好风味', 'ko' => '선호하는 맛', 'ja' => 'お好みの味わい' ),
				'sp_admin_brew_label' => array( 'en' => 'Brew style', 'vi' => 'Cách pha', 'ru' => 'Способ приготовления', 'hi' => 'ब्रू शैली', 'zh' => '冲煮方式', 'ko' => '추출 방식', 'ja' => '抽出方法' ),
				'wi_admin_heading' => array( 'en' => 'New wholesale quote request', 'vi' => 'Yêu cầu báo giá sỉ mới', 'ru' => 'Новый оптовый запрос цены', 'hi' => 'नया थोक मूल्य अनुरोध', 'zh' => '新的批发询价', 'ko' => '새 도매 견적 요청', 'ja' => '新しい卸売り見積もり依頼' ),
				'wi_admin_subject' => array(
					'en' => '[{site_title}] New wholesale quote request — {business_name}',
					'vi' => '[{site_title}] Yêu cầu báo giá sỉ mới — {business_name}',
					'ru' => '[{site_title}] Новый оптовый запрос — {business_name}',
					'hi' => '[{site_title}] नया थोक मूल्य अनुरोध — {business_name}',
					'zh' => '[{site_title}] 新的批发询价 — {business_name}',
					'ko' => '[{site_title}] 새 도매 견적 요청 — {business_name}',
					'ja' => '[{site_title}] 新しい卸売り見積もり依頼 — {business_name}',
				),
				'wi_admin_intro' => array(
					'en' => 'A new wholesale quote request was submitted from the website.',
					'vi' => 'Có một yêu cầu báo giá sỉ mới được gửi từ website.',
					'ru' => 'С сайта поступил новый оптовый запрос цены.',
					'hi' => 'वेबसाइट से थोक मूल्य का नया अनुरोध भेजा गया है।',
					'zh' => '网站收到了一份新的批发询价。',
					'ko' => '웹사이트에서 새 도매 견적 요청이 접수되었습니다.',
					'ja' => 'ウェブサイトから新しい卸売り見積もりの依頼がありました。',
				),
				'wi_admin_business_label' => array( 'en' => 'Business name', 'vi' => 'Tên doanh nghiệp', 'ru' => 'Название компании', 'hi' => 'व्यवसाय का नाम', 'zh' => '公司名称', 'ko' => '사업체명', 'ja' => '会社名' ),
				'wi_admin_contact_label' => array( 'en' => 'Contact', 'vi' => 'Liên hệ', 'ru' => 'Контакт', 'hi' => 'संपर्क', 'zh' => '联系方式', 'ko' => '연락처', 'ja' => '連絡先' ),
				'wi_admin_topic_label' => array( 'en' => 'Request type', 'vi' => 'Loại yêu cầu', 'ru' => 'Тип запроса', 'hi' => 'अनुरोध का प्रकार', 'zh' => '咨询类型', 'ko' => '문의 유형', 'ja' => 'お問い合わせ種別' ),
				'wi_admin_details_label' => array( 'en' => 'Details', 'vi' => 'Nội dung', 'ru' => 'Содержание', 'hi' => 'विवरण', 'zh' => '内容', 'ko' => '내용', 'ja' => '内容' ),
				'wo_admin_heading' => array( 'en' => 'New wholesale order', 'vi' => 'Đơn hàng sỉ mới', 'ru' => 'Новый оптовый заказ', 'hi' => 'नया थोक ऑर्डर', 'zh' => '新的批发订单', 'ko' => '새 도매 주문', 'ja' => '新しい卸売りご注文' ),
				'wo_admin_subject' => array(
					'en' => '[{site_title}] New wholesale order — {order_number}',
					'vi' => '[{site_title}] Đơn hàng sỉ mới — {order_number}',
					'ru' => '[{site_title}] Новый оптовый заказ — {order_number}',
					'hi' => '[{site_title}] नया थोक ऑर्डर — {order_number}',
					'zh' => '[{site_title}] 新的批发订单 — {order_number}',
					'ko' => '[{site_title}] 새 도매 주문 — {order_number}',
					'ja' => '[{site_title}] 新しい卸売りご注文 — {order_number}',
				),
				'wo_admin_intro' => array(
					'en' => 'A new wholesale order %1$s was placed by customer %2$s (%3$s).',
					'vi' => 'Có một đơn hàng sỉ mới %1$s từ khách hàng %2$s (%3$s).',
					'ru' => 'Новый оптовый заказ %1$s от клиента %2$s (%3$s).',
					'hi' => 'ग्राहक %2$s (%3$s) ने नया थोक ऑर्डर %1$s दिया है।',
					'zh' => '客户 %2$s（%3$s）提交了新的批发订单 %1$s。',
					'ko' => '고객 %2$s(%3$s)이(가) 새 도매 주문 %1$s을(를) 접수했습니다.',
					'ja' => 'お客様 %2$s（%3$s）より新しい卸売りご注文 %1$s を承りました。',
				),
				'wo_admin_vip_note' => array(
					'en' => 'VIP order — the customer does not see prices. Please price every item in WooCommerce → Wholesale Orders before approving. The price you enter is final (no level discount applied).',
					'vi' => 'Đơn hàng VIP — khách hàng không thấy giá. Vui lòng định giá từng sản phẩm trong WooCommerce → Wholesale Orders trước khi duyệt đơn. Giá bạn nhập là giá cuối cùng (không áp dụng chiết khấu theo mức giá).',
					'ru' => 'VIP-заказ — клиент не видит цены. Укажите цену для каждой позиции в WooCommerce → Wholesale Orders перед одобрением. Введённая цена окончательная (скидка по уровню не применяется).',
					'hi' => 'VIP ऑर्डर — ग्राहक को कीमतें नहीं दिखतीं। मंज़ूरी से पहले WooCommerce → Wholesale Orders में हर आइटम की कीमत तय करें। आपकी डाली कीमत अंतिम है (स्तर छूट लागू नहीं)।',
					'zh' => 'VIP 订单——客户看不到价格。审批前请在 WooCommerce → Wholesale Orders 中为每项定价。你填写的价格为最终价（不适用级别折扣）。',
					'ko' => 'VIP 주문 — 고객에게 가격이 보이지 않습니다. 승인 전 WooCommerce → Wholesale Orders에서 각 항목의 가격을 정하세요. 입력한 가격이 최종가입니다(등급 할인 미적용).',
					'ja' => 'VIP ご注文 — お客様には価格が表示されません。承認前に WooCommerce → Wholesale Orders で各商品の価格を設定してください。入力した価格が最終価格です（レベル割引は適用されません）。',
				),
				'wo_admin_items_label' => array( 'en' => 'Item list', 'vi' => 'Danh sách sản phẩm', 'ru' => 'Список товаров', 'hi' => 'आइटम सूची', 'zh' => '商品列表', 'ko' => '상품 목록', 'ja' => '商品リスト' ),
				'wo_admin_customer_note' => array( 'en' => 'Customer note', 'vi' => 'Ghi chú của khách hàng', 'ru' => 'Заметка клиента', 'hi' => 'ग्राहक का नोट', 'zh' => '客户备注', 'ko' => '고객 메모', 'ja' => 'お客様のご要望' ),
				'wo_admin_process_note' => array(
					'en' => 'Handle this order in WooCommerce → Wholesale Orders.',
					'vi' => 'Xử lý đơn hàng này trong WooCommerce → Wholesale Orders.',
					'ru' => 'Обработайте этот заказ в WooCommerce → Wholesale Orders.',
					'hi' => 'इस ऑर्डर को WooCommerce → Wholesale Orders में संभालें।',
					'zh' => '请在 WooCommerce → Wholesale Orders 中处理此订单。',
					'ko' => '이 주문은 WooCommerce → Wholesale Orders에서 처리하세요.',
					'ja' => 'このご注文は WooCommerce → Wholesale Orders で処理してください。',
				),
				'wo_admin_unpriced' => array( 'en' => 'not yet priced', 'vi' => 'chưa định giá', 'ru' => 'цена не задана', 'hi' => 'मूल्य तय नहीं', 'zh' => '未定价', 'ko' => '미정가', 'ja' => '価格未設定' ),
			);
		}

		$locale = is_string( $locale ) ? strtolower( trim( $locale ) ) : '';
		if ( strlen( $locale ) > 2 && false !== strpos( $locale, '-' ) ) {
			$locale = substr( $locale, 0, 2 );
		}

		if ( isset( $strings[ $key ][ $locale ] ) ) {
			return $strings[ $key ][ $locale ];
		}
		if ( isset( $strings[ $key ]['en'] ) ) {
			return $strings[ $key ]['en'];
		}
		return '';
	}

	/**
	 * Normalizes a storefront locale to a supported base code, falling back to
	 * Vietnamese (the store's primary customer base) for unknown/blank values
	 * such as "unknown".
	 *
	 * @param string $locale
	 * @return string
	 */
	function epic_email_locale( $locale ) {
		$supported = array( 'en', 'vi', 'ru', 'hi', 'zh', 'ko', 'ja' );
		$locale    = is_string( $locale ) ? strtolower( trim( $locale ) ) : '';
		if ( strlen( $locale ) > 2 && false !== strpos( $locale, '-' ) ) {
			$locale = substr( $locale, 0, 2 );
		}
		return in_array( $locale, $supported, true ) ? $locale : 'vi';
	}
}
