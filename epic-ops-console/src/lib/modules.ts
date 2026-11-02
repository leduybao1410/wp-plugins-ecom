export const modules = [
  { id: "overview", label: "Tổng quan", href: "/", resource: "overview" },
  { id: "orders", label: "Đơn hàng", href: "/orders", resource: "orders" },
  { id: "shipments", label: "Vận chuyển", href: "/shipments", resource: "shipments" },
  { id: "products", label: "Sản phẩm", href: "/products", resource: "products" },
  { id: "customers", label: "Khách hàng", href: "/customers", resource: "customers" },
  { id: "support", label: "Hội thoại hỗ trợ", href: "/support/conversations", resource: "support" },
  { id: "faqs", label: "FAQ", href: "/faqs", resource: "faqs" },
  { id: "leads", label: "Khách tiềm năng", href: "/leads", resource: "leads" },
  { id: "wholesale-orders", label: "Bán sỉ", href: "/wholesale-orders", resource: "wholesale-orders" },
  { id: "content", label: "Nội dung", href: "/content", resource: "content" },
  { id: "marketing", label: "Tiếp thị", href: "/marketing", resource: "newsletter" },
  { id: "reviews", label: "Đánh giá", href: "/reviews", resource: "reviews" },
  { id: "finance", label: "Tài chính", href: "/finance", resource: "costs" },
  { id: "settings", label: "Cài đặt kinh doanh", href: "/settings", resource: "health" },
] as const;

export const moduleTitles: Record<string, string> = Object.fromEntries(modules.map(({ id, label }) => [id, label]));
