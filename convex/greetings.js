import { query } from "./_generated/server";

export const hello = query({
  args: {},
  handler: async () => {
    return {
      title: "Привет Герман!",
      description: "Эта страница уже подключена к Convex.",
    };
  },
});
