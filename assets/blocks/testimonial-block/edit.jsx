/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */
import { useState, useEffect } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    RadioControl,
    ToggleControl,
    RangeControl,
    FormTokenField,
    Placeholder,
    Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';

export default function Edit({ attributes, setAttributes }) {
    const { mode, shortcodeId, viewId, category, limit, orderby, order, featuredOnly, minRating, showPhoto, showRating, showRole, showCompany, showDate, showWebsite, showQuoteIcon } = attributes;
    const blockProps = useBlockProps({ className: 'plugixa-testimonial-block' });

    const [shortcodes, setShortcodes] = useState([]);
    const [views, setViews] = useState([]);
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        Promise.all([
            apiFetch({ path: '/plugixatestimonial/v1/shortcodes?limit=100' }).catch(() => ({ data: { items: [] } })),
            apiFetch({ path: '/plugixatestimonial/v1/views?limit=100' }).catch(() => ({ data: { items: [] } })),
            apiFetch({ path: '/plugixatestimonial/v1/categories?limit=100' }).catch(() => ({ data: { items: [] } })),
        ]).then(([scRes, viewRes, catRes]) => {
            setShortcodes(scRes?.data?.items || scRes?.items || []);
            setViews(viewRes?.data?.items || viewRes?.items || []);
            setCategories(catRes?.data?.items || catRes?.items || []);
            setLoading(false);
        });
    }, []);

    const shortcodeOptions = [
        { label: __('Select a shortcode...', 'plugixa-testimonial'), value: 0 },
        ...shortcodes.map((sc) => ({ label: sc.name, value: sc.id })),
    ];

    const viewOptions = [
        { label: __('Default View', 'plugixa-testimonial'), value: 0 },
        ...views.map((v) => ({ label: v.name, value: v.id })),
    ];

    const orderbyOptions = [
        { label: __('Display Order', 'plugixa-testimonial'), value: 'display_order' },
        { label: __('Date', 'plugixa-testimonial'), value: 'created_at' },
        { label: __('Rating', 'plugixa-testimonial'), value: 'rating' },
        { label: __('Name', 'plugixa-testimonial'), value: 'client_name' },
    ];

    const orderOptions = [
        { label: __('Ascending', 'plugixa-testimonial'), value: 'ASC' },
        { label: __('Descending', 'plugixa-testimonial'), value: 'DESC' },
    ];

    // Category tokens
    const selectedCategoryIds = category ? category.split(',').map(Number).filter(Boolean) : [];
    const selectedCategoryNames = selectedCategoryIds
        .map((id) => categories.find((c) => c.id === id))
        .filter(Boolean)
        .map((c) => c.name);
    const categorySuggestions = categories.map((c) => c.name);

    const onCategoryChange = (tokens) => {
        const ids = tokens
            .map((name) => categories.find((c) => c.name === name))
            .filter(Boolean)
            .map((c) => c.id);
        setAttributes({ category: ids.join(',') });
    };

    const hasConfig = mode === 'existing' ? shortcodeId > 0 : true;

    return (
        <div {...blockProps}>
            <InspectorControls>
                <PanelBody title={__('Display Mode', 'plugixa-testimonial')} initialOpen={true}>
                    <RadioControl
                        selected={mode}
                        options={[
                            { label: __('Use Saved Shortcode', 'plugixa-testimonial'), value: 'existing' },
                            { label: __('Custom Configuration', 'plugixa-testimonial'), value: 'custom' },
                        ]}
                        onChange={(value) => setAttributes({ mode: value })}
                    />
                </PanelBody>

                {mode === 'existing' && (
                    <PanelBody title={__('Select Shortcode', 'plugixa-testimonial')} initialOpen={true}>
                        {loading ? (
                            <Spinner />
                        ) : (
                            <SelectControl
                                label={__('Shortcode', 'plugixa-testimonial')}
                                value={shortcodeId}
                                options={shortcodeOptions}
                                onChange={(value) => setAttributes({ shortcodeId: parseInt(value) })}
                            />
                        )}
                    </PanelBody>
                )}

                {mode === 'custom' && (
                    <>
                        <PanelBody title={__('Display Settings', 'plugixa-testimonial')} initialOpen={true}>
                            {loading ? (
                                <Spinner />
                            ) : (
                                <>
                                    <SelectControl
                                        label={__('View', 'plugixa-testimonial')}
                                        value={viewId}
                                        options={viewOptions}
                                        onChange={(value) => setAttributes({ viewId: parseInt(value) })}
                                    />
                                    <FormTokenField
                                        label={__('Categories', 'plugixa-testimonial')}
                                        value={selectedCategoryNames}
                                        suggestions={categorySuggestions}
                                        onChange={onCategoryChange}
                                    />
                                    <ToggleControl
                                        label={__('Featured Only', 'plugixa-testimonial')}
                                        checked={featuredOnly}
                                        onChange={(value) => setAttributes({ featuredOnly: value })}
                                    />
                                    <RangeControl
                                        label={__('Minimum Rating', 'plugixa-testimonial')}
                                        value={minRating}
                                        onChange={(value) => setAttributes({ minRating: value })}
                                        min={0}
                                        max={5}
                                    />
                                </>
                            )}
                        </PanelBody>

                        <PanelBody title={__('Query', 'plugixa-testimonial')} initialOpen={false}>
                            <RangeControl
                                label={__('Limit', 'plugixa-testimonial')}
                                value={limit}
                                onChange={(value) => setAttributes({ limit: value })}
                                min={0}
                                max={100}
                                help={__('0 = no limit', 'plugixa-testimonial')}
                            />
                            <SelectControl
                                label={__('Order By', 'plugixa-testimonial')}
                                value={orderby}
                                options={orderbyOptions}
                                onChange={(value) => setAttributes({ orderby: value })}
                            />
                            <SelectControl
                                label={__('Order', 'plugixa-testimonial')}
                                value={order}
                                options={orderOptions}
                                onChange={(value) => setAttributes({ order: value })}
                            />
                        </PanelBody>

                        <PanelBody title={__('Display Options', 'plugixa-testimonial')} initialOpen={false}>
                            <ToggleControl
                                label={__('Show Photo', 'plugixa-testimonial')}
                                checked={showPhoto}
                                onChange={(value) => setAttributes({ showPhoto: value })}
                            />
                            <ToggleControl
                                label={__('Show Rating', 'plugixa-testimonial')}
                                checked={showRating}
                                onChange={(value) => setAttributes({ showRating: value })}
                            />
                            <ToggleControl
                                label={__('Show Role', 'plugixa-testimonial')}
                                checked={showRole}
                                onChange={(value) => setAttributes({ showRole: value })}
                            />
                            <ToggleControl
                                label={__('Show Company', 'plugixa-testimonial')}
                                checked={showCompany}
                                onChange={(value) => setAttributes({ showCompany: value })}
                            />
                            <ToggleControl
                                label={__('Show Date', 'plugixa-testimonial')}
                                checked={showDate}
                                onChange={(value) => setAttributes({ showDate: value })}
                            />
                            <ToggleControl
                                label={__('Show Website', 'plugixa-testimonial')}
                                checked={showWebsite}
                                onChange={(value) => setAttributes({ showWebsite: value })}
                            />
                            <ToggleControl
                                label={__('Show Quote Icon', 'plugixa-testimonial')}
                                checked={showQuoteIcon}
                                onChange={(value) => setAttributes({ showQuoteIcon: value })}
                            />
                        </PanelBody>
                    </>
                )}
            </InspectorControls>

            {!hasConfig ? (
                <Placeholder
                    icon="star-filled"
                    label={__('Plugixa Testimonials', 'plugixa-testimonial')}
                    instructions={__('Select a saved shortcode from the block settings panel.', 'plugixa-testimonial')}
                />
            ) : (
                <ServerSideRender
                    block="plugixa-testimonial/testimonial-display"
                    attributes={attributes}
                    EmptyResponsePlaceholder={() => (
                        <Placeholder
                            icon="star-filled"
                            label={__('Plugixa Testimonials', 'plugixa-testimonial')}
                            instructions={__('No testimonials found for the current configuration.', 'plugixa-testimonial')}
                        />
                    )}
                />
            )}
        </div>
    );
}
