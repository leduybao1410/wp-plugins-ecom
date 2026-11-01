export {};

declare global {
  interface Window {
    EPIC_OPS?: {
      base: string;
      apiRoot: string;
      token: string;
      logoutUrl: string;
      user: { id: number; name: string; email: string };
      version: string;
    };
  }
}
