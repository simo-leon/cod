<?php

namespace Webkul\Shop\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Models\ProductReview;
use Webkul\Shop\Http\Requests\ContactRequest;
use Webkul\Shop\Http\Resources\CategoryTreeResource;
use Webkul\Shop\Mail\ContactUs;
use Webkul\Theme\Repositories\ThemeCustomizationRepository;

class HomeController extends Controller
{
    /**
     * Using const variable for status
     */
    const STATUS = 1;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected ThemeCustomizationRepository $themeCustomizationRepository, protected CategoryRepository $categoryRepository) {}

    /**
     * Loads the home page for the storefront.
     *
     * @return View
     */
    public function index()
    {
        $customizations = $this->themeCustomizationRepository->orderBy('sort_order')->findWhere([
            'status' => self::STATUS,
            'channel_id' => core()->getCurrentChannel()->id,
            'theme_code' => core()->getCurrentChannel()->theme,
        ]);

        $categories = $this->categoryRepository->getVisibleCategoryTree(core()->getCurrentChannel()->root_category_id);

        $categories = CategoryTreeResource::collection($categories);

        /*
            Jemla: data for the newer homepage topics, fetched only
            when such a topic is actually active on the homepage.
            - shop_by_category: top-level category models (with
              logo image + url), the tree resource above carries no
              image.
            - customer_reviews: latest approved product reviews.
        */
        $types = $customizations->pluck('type')->all();

        $shopCategories = in_array('shop_by_category', $types)
            ? collect($this->categoryRepository->getVisibleCategoryTree(core()->getCurrentChannel()->root_category_id))
            : collect();

        $latestReviews = in_array('customer_reviews', $types)
            ? ProductReview::query()
                ->where('status', 'approved')
                ->orderByDesc('created_at')
                ->limit(12)
                ->get()
            : collect();

        return view('shop::home.index', compact('customizations', 'categories', 'shopCategories', 'latestReviews'));
    }

    /**
     * Loads the categories listing page (Jemla: the category
     * carousel's "view all" target — it used to link back to the
     * home page). Shows the visible top-level categories of the
     * current channel's root category.
     */
    public function categories(): View
    {
        $categories = $this->categoryRepository->getVisibleCategoryTree(core()->getCurrentChannel()->root_category_id);

        return view('shop::categories.index', compact('categories'));
    }

    /**
     * Loads the home page for the storefront if something wrong.
     *
     * @return \Exception
     */
    public function notFound()
    {
        abort(404);
    }

    /**
     * Summary of contact.
     *
     * @return View
     */
    public function contactUs()
    {
        return view('shop::home.contact-us');
    }

    /**
     * Summary of store.
     *
     * @return RedirectResponse
     */
    public function sendContactUsMail(ContactRequest $contactRequest)
    {
        try {
            Mail::queue(new ContactUs($contactRequest->only([
                'name',
                'email',
                'contact',
                'message',
            ])));

            session()->flash('success', trans('shop::app.home.thanks-for-contact'));
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());

            report($e);
        }

        return back();
    }
}
