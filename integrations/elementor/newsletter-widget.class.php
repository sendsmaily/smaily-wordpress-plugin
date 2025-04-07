<?php

namespace Smaily_WP_Connect\Integrations\Elementor;

use Smaily_WP_Connect\Includes\Options;
use Smaily_WP_Connect\Includes\Helper;
use Elementor\Widget_Base;

class Newsletter_Widget extends Widget_Base {
	/**
	 * Smaily WP Connect plugin options.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * List of autoresponders as [key => value] pairs where key is the autoresponder ID
	 * and value is the autoresponder title.
	 *
	 * @var array
	 */
	private $autoresponders;

	/**
	 * Get the programmatic name of the widget.
	 *
	 * @return string The name of the widget.
	 */
	public function get_name() {
		return SMAILY_WP_CONNECT_PLUGIN_NAME . '-elementor-newsletter-widget';
	}

	/**
	 * Get the visible title of the widget.
	 *
	 * @return string The title of the widget.
	 */
	public function get_title() {
		return __( 'Smaily Newsletter', 'smaily-wp-connect' );
	}

	/**
	 * Get the icon of the widget. Elementor or Font Awesome icons can be used.
	 * See:
	 * https://elementor.github.io/elementor-icons/
	 * https://fontawesome.com/
	 *
	 * @return string The icon of the widget.
	 */
	public function get_icon() {
		return 'eicon-mail';
	}

	/**
	 * Get the categories of the widget.
	 *
	 * @return array The categories of the widget.
	 */
	public function get_categories() {
		return array( Admin::WIDGET_CATEGORY );
	}

	/**
	 * Get the search keywords for the widget.
	 *
	 * @return array The keywords for the widget.
	 */
	public function get_keywords() {
		return array(
			__( 'newsletter', 'smaily-wp-connect' ),
			__( 'email', 'smaily-wp-connect' ),
			__( 'subscribe', 'smaily-wp-connect' ),
			__( 'form', 'smaily-wp-connect' ),
			__( 'smaily', 'smaily-wp-connect' ),
		);
	}

	/**
	 * Register the controls for the widget.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_content_tab_visible_controls();
		$this->register_content_tab_hidden_controls();
		$this->register_style_tab_controls();
	}

	/**
	 * Render the frontend output of the widget.
	 *
	 * @return void
	 */
	protected function render() {
		$options     = $this->get_options();
		$current_url = Helper::get_current_url();

		if ( ! $options->has_credentials() ) {
			?>
				<div class="error">
					<p>
						<?php echo esc_html__( 'Please configure the Smaily WP Connect plugin settings first.', 'smaily-wp-connect' ); ?>
					</p>
				</div>
			<?php
			return;
		}

		$settings_for_display = $this->get_settings_for_display();
		$parameters           = array(
			'autoresponder_id'  => $settings_for_display['autoresponder_id'] ?? '',
			'button_text'       => $settings_for_display['button_text'] ?? '',
			'email_label'       => $settings_for_display['email_label'] ?? '',
			'email_placeholder' => $settings_for_display['email_placeholder'] ?? '',
			'error_message'     => $settings_for_display['error_message'] ?? '',
			'language_code'     => Helper::get_current_language_code(),
			'failure_url'       => $settings_for_display['failure_url']['url'] ?? Helper::get_current_url(),
			'name_label'        => $settings_for_display['name_label'] ?? '',
			'name_placeholder'  => $settings_for_display['name_placeholder'] ?? '',
			'show_name'         => $settings_for_display['show_name'] === 'yes',
			'subdomain'         => $options->get_subdomain(),
			'success_message'   => $settings_for_display['success_message'] ?? '',
			'success_url'       => $settings_for_display['success_url']['url'] ?? Helper::get_current_url(),
		);

		$optin_form_response  = Helper::get_optin_form_response_type();
		$is_preview_mode      = \Elementor\Plugin::$instance->editor->is_edit_mode();
		$show_notice          = $is_preview_mode || $optin_form_response !== false;
		$show_success_message = $is_preview_mode || ( $optin_form_response === 'success' && $parameters['success_message'] );
		$show_error_message   = $is_preview_mode || ( $optin_form_response === 'error' && $parameters['error_message'] );

		?>
		<div class="smaily-wp-connect-elementor-newsletter-form-wrapper">
			<?php if ( $show_notice ) : ?>
				<div class="smaily-wp-connect-elementor-newsletter-form-notice__container">
					<?php if ( $show_success_message ) : ?>
						<div
							class="smaily-wp-connect-elementor-newsletter-form-notice__content success"
							id="smaily-wp-connect-elementor-newsletter-form-success-message"
						>
							<?php echo wp_kses_post( $parameters['success_message'] ); ?>
						</div>
					<?php endif; ?>
					<?php if ( $show_error_message ) : ?>
						<div
							class="smaily-wp-connect-elementor-newsletter-form-notice__content error"
							id="smaily-wp-connect-elementor-newsletter-form-error-message"
						>
							<?php echo wp_kses_post( $parameters['error_message'] ); ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<form
				class="smaily-wp-connect-elementor-newsletter-form"
				action="https://<?php echo esc_attr( $parameters['subdomain'] ); ?>.sendsmaily.net/api/opt-in/"
				method="post"
			>
				<?php if ( $parameters['autoresponder_id'] ) : ?>
					<input
						type="hidden"
						name="autoresponder"
						value="<?php echo esc_attr( $parameters['autoresponder_id'] ); ?>"
					>
				<?php endif; ?>
				<input
						type="hidden"
						name="language"
						value="<?php echo esc_attr( $parameters['language_code'] ); ?>"
				>
				<input
					type="hidden"
					name="success_url"
					value="<?php echo ! empty( $parameters['success_url'] ) ? esc_url( $parameters['success_url'] ) : esc_url( $current_url ); ?>"
				>
				<input
					type="hidden"
					name="failure_url"
					value="<?php echo ! empty( $parameters['success_url'] ) ? esc_url( $parameters['success_url'] ) : esc_url( $current_url ); ?>"
				>
				<div class="smaily-wp-connect-elementor-newsletter-form-visible-fields">
					<div class="smaily-wp-connect-elementor-newsletter-form-input-container">
						<?php if ( $parameters['email_label'] ) : ?>
							<label for="smaily-wp-connect-elementor-newsletter-form-email">
								<?php echo esc_html( $parameters['email_label'] ); ?>
							</label>
						<?php endif; ?>
						<input
							id="smaily-wp-connect-elementor-newsletter-form-email"
							type="email"
							name="email"
							value=""
							placeholder="<?php echo esc_attr( $parameters['email_placeholder'] ); ?>"
							required
						>
					</div>
					<?php if ( $parameters['show_name'] ) : ?>
						<div class="smaily-wp-connect-elementor-newsletter-form-input-container">
							<?php if ( $parameters['name_label'] ) : ?>
								<label for="smaily-wp-connect-elementor-newsletter-form-name">
									<?php echo esc_html( $parameters['name_label'] ); ?>
								</label>
							<?php endif; ?>
								<input
									id="smaily-wp-connect-elementor-newsletter-form-name"
									type="text"
									name="name"
									value=""
									placeholder="<?php echo esc_attr( $parameters['name_placeholder'] ); ?>"
								>
						</div>
					<?php endif; ?>
				</div>
				<button type="submit" class="smaily-wp-connect-elementor-newsletter-form-submit-button">
					<?php echo esc_html( $parameters['button_text'] ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the preview output in the editor.
	 *
	 * @return void
	 */
	protected function content_template() {
		$options = $this->get_options();
		if ( ! $options->has_credentials() ) {
			?>
				<div class="error">
					<p>
						<?php echo esc_html__( 'Please configure the Smaily WP Connect plugin settings first.', 'smaily-wp-connect' ); ?>
					</p>
				</div>
			<?php
			return;
		}

		?>
		<div class="smaily-wp-connect-elementor-newsletter-form-wrapper">
			<div class="smaily-wp-connect-elementor-newsletter-form-notice__container">
				<# if ( settings.success_message !== '' ) {#>
				<div
					class="smaily-wp-connect-elementor-newsletter-form-notice__content success"
					id="smaily-wp-connect-elementor-newsletter-form-success-message"
				>
					{{ settings.success_message }}
				</div>
				<# } #>
				<# if ( settings.error_message !== '' ) { #>
				<div
					class="smaily-wp-connect-elementor-newsletter-form-notice__content error"
					id="smaily-wp-connect-elementor-newsletter-form-error-message"
				>
					{{ settings.error_message }}
				</div>
				<# } #>
			</div>
			<form class="smaily-wp-connect-elementor-newsletter-form"">
				<div class="smaily-wp-connect-elementor-newsletter-form-visible-fields">
					<div class="smaily-wp-connect-elementor-newsletter-form-input-container">
						<label for="smaily-wp-connect-elementor-newsletter-form-email">
							{{ settings.email_label }}
						</label>
						<input
							id="smaily-wp-connect-elementor-newsletter-form-email"
							type="email"
							name="email"
							value=""
							placeholder="{{ settings.email_placeholder }}"
							required
						>
					</div>
					<# if ( settings.show_name ) { #>
						<div class="smaily-wp-connect-elementor-newsletter-form-input-container">
							<label for="smaily-wp-connect-elementor-newsletter-form-name">
								{{ settings.name_label }}
							</label>
							<input
								type="text"
								name="name"
								value=""
								placeholder="{{ settings.name_placeholder }}"
							>
						</div>
					<# } #>
				</div>
				<button type="submit" class="smaily-wp-connect-elementor-newsletter-form-submit-button">
					{{ settings.button_text }}
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Lazy load the Smaily WP Connect plugin options.
	 *
	 * @return Options The Smaily WP Connect plugin options.
	 */
	private function get_options() {
		if ( ! $this->options ) {
			$this->options = new Options();
		}

		return $this->options;
	}

	/**
	 * Lazy load the list of autoresponders.
	 *
	 * @return array The list of autoresponders.
	 */
	private function listAutoresponders() {
		if ( ! $this->autoresponders ) {
			$autoresponders = Helper::get_autoresponders_list( $this->get_options() );
			foreach ( $autoresponders as $id => $title ) {
				$this->autoresponders[ esc_attr( $id ) ] = esc_attr( $title );
			}
			$this->autoresponders[''] = __( 'No autoresponder', 'smaily-wp-connect' );
		}

		return $this->autoresponders;
	}

	/**
	 * Register the content tab controls for visible fields.
	 *
	 * @return void
	 */
	private function register_content_tab_visible_controls() {
		$this->start_controls_section(
			'visible_fields',
			array(
				'label' => __( 'Visible Fields', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'email_label',
			array(
				'label'       => __( 'Email Label', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Email', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter your email', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'email_placeholder',
			array(
				'label'       => __( 'Email Placeholder', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Enter your email', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter your email', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'show_name',
			array(
				'label'        => __( 'Show Name Field', 'smaily-wp-connect' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'smaily-wp-connect' ),
				'label_off'    => __( 'No', 'smaily-wp-connect' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'name_label',
			array(
				'label'       => __( 'Name Label', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Name', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter your name', 'smaily-wp-connect' ),
				'condition'   => array(
					'show_name' => 'yes',
				),
			)
		);

		$this->add_control(
			'name_placeholder',
			array(
				'label'       => __( 'Name Placeholder', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Enter your name', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter your name', 'smaily-wp-connect' ),
				'condition'   => array(
					'show_name' => 'yes',
				),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => __( 'Button Text', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Subscribe', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter button text', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'success_message',
			array(
				'label'       => __( 'Success Message', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Thank you for subscribing!', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter success message', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'error_message',
			array(
				'label'       => __( 'Error Message', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'An error occurred. Please try again.', 'smaily-wp-connect' ),
				'placeholder' => __( 'Enter error message', 'smaily-wp-connect' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the content tab controls for hidden fields.
	 *
	 * @return void
	 */
	private function register_content_tab_hidden_controls() {
		$this->start_controls_section(
			'hidden_fields',
			array(
				'label' => __( 'Hidden Fields', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'autoresponder_id',
			array(
				'label'       => __( 'Autoresponder', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => $this->listAutoresponders(),
				'label_block' => true,
				'description' => __( 'Select an autoresponder if you want to target a specific automation workflow.', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'success_url',
			array(
				'label'       => __( 'Success URL', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => '',
				),
				'options'     => false,
				'placeholder' => __( 'Enter success URL', 'smaily-wp-connect' ),
			)
		);

		$this->add_control(
			'failure_url',
			array(
				'label'       => __( 'Failure URL', 'smaily-wp-connect' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => '',

				),
				'description' => __( 'Note: URLs are optional. If left empty, the current page URL will be used.', 'smaily-wp-connect' ),
				'options'     => false,
				'placeholder' => __( 'Enter failure URL', 'smaily-wp-connect' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the style tab controls.
	 *
	 * @return void
	 */
	private function register_style_tab_controls() {
		$this->start_controls_section(
			'section_styles',
			array(
				'label' => __( 'Container', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'container_orientation',
			array(
				'label'     => __( 'Orientation', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'column',
				'options'   => array(
					'column' => __( 'Vertical', 'smaily-wp-connect' ),
					'row'    => __( 'Horizontal', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form' => 'flex-direction: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'container_background_color',
			array(
				'label'     => __( 'Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'container_padding',
			array(
				'label'      => __( 'Padding', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'container_border_style_pre_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'container_border_style',
			array(
				'label'     => __( 'Border Style', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'none',
				'options'   => array(
					'none'   => __( 'None', 'smaily-wp-connect' ),
					'solid'  => __( 'Solid', 'smaily-wp-connect' ),
					'dashed' => __( 'Dashed', 'smaily-wp-connect' ),
					'dotted' => __( 'Dotted', 'smaily-wp-connect' ),
					'double' => __( 'Double', 'smaily-wp-connect' ),
					'groove' => __( 'Groove', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'border-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'container_border_color',
			array(
				'label'     => __( 'Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'container_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'container_border_width',
			array(
				'label'      => __( 'Border Width', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'container_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'container_border_radius',
			array(
				'label'      => __( 'Border Radius', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-wrapper' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'container_border_style!' => 'none',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_labels',
			array(
				'label' => __( 'Labels', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'labels_color',
			array(
				'label'     => __( 'Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container label' => 'color: {{VALUE}};',
				),
				'global'    => array(
					'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_TEXT,
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'labels_typography',
				'selector' => '{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container label',
			)
		);

		$this->add_control(
			'labels_margin_bottom',
			array(
				'label'      => __( 'Bottom Gap', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'default'    => array(
					'size' => 0,
					'unit' => 'px',
				),
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container label' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_fields',
			array(
				'label' => __( 'Input Fields', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'fields_background_color',
			array(
				'label'     => __( 'Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'fields_height',
			array(
				'label'      => __( 'Height', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'default'    => array(
					'size' => 40,
					'unit' => 'px',
				),
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'fields_typography',
				'selector' => '{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input',
			)
		);

		$this->add_control(
			'fields_border_style_pre_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'fields_border_style',
			array(
				'label'     => __( 'Border Style', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'solid',
				'options'   => array(
					'none'   => __( 'None', 'smaily-wp-connect' ),
					'solid'  => __( 'Solid', 'smaily-wp-connect' ),
					'dashed' => __( 'Dashed', 'smaily-wp-connect' ),
					'dotted' => __( 'Dotted', 'smaily-wp-connect' ),
					'double' => __( 'Double', 'smaily-wp-connect' ),
					'groove' => __( 'Groove', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'border-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'fields_border_color',
			array(
				'label'     => __( 'Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'fields_border_style!' => 'none',
				),
				'global'    => array(
					'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_TEXT,
				),
			)
		);

		$this->add_control(
			'fields_border_width',
			array(
				'label'      => __( 'Border Width', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => 1,
					'right'  => 1,
					'bottom' => 1,
					'left'   => 1,
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'fields_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'fields_border_radius',
			array(
				'label'      => __( 'Border Radius', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => 2,
					'right'  => 2,
					'bottom' => 2,
					'left'   => 2,
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'fields_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'fields_border_hover_pre_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'fields_border_hover_color',
			array(
				'label'     => __( 'Border Hover Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'fields_border_focus_color',
			array(
				'label'     => __( 'Border Focus Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-input-container input:focus' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_button',
			array(
				'label' => __( 'Button', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_background_color',
			array(
				'label'     => __( 'Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'background-color: {{VALUE}};',
				),
				'global'    => array(
					'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_height',
			array(
				'label'      => __( 'Height', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'default'    => array(
					'size' => 40,
					'unit' => 'px',
				),
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_top_margin',
			array(
				'label'      => __( 'Top Gap', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'default'    => array(
					'size' => 8,
					'unit' => 'px',
				),
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'button_border_style_pre_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'button_border_style',
			array(
				'label'     => __( 'Border Style', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'none',
				'options'   => array(
					'none'   => __( 'None', 'smaily-wp-connect' ),
					'solid'  => __( 'Solid', 'smaily-wp-connect' ),
					'dashed' => __( 'Dashed', 'smaily-wp-connect' ),
					'dotted' => __( 'Dotted', 'smaily-wp-connect' ),
					'double' => __( 'Double', 'smaily-wp-connect' ),
					'groove' => __( 'Groove', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'border-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_border_color',
			array(
				'label'     => __( 'Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'button_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'button_border_width',
			array(
				'label'      => __( 'Border Width', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'button_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'    => 2,
					'right'  => 2,
					'bottom' => 2,
					'left'   => 2,
					'unit'   => 'px',
				),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'button_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'button_hover_style_pre_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'button_hover_background_color',
			array(
				'label'     => __( 'Hover Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => __( 'Hover Text Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_border_color',
			array(
				'label'     => __( 'Hover Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-submit-button:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_success_error_messages',
			array(
				'label' => __( 'Success/Error Messages', 'smaily-wp-connect' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'messages_color',
			array(
				'label'     => __( 'Text Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__container' => 'color: {{VALUE}};',
				),
				'global'    => array(
					'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_TEXT,
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'messages_typography',
				'selector' => '{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__container',
			)
		);

		$this->add_control(
			'messages_error_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'messages_error_background_color',
			array(
				'label'     => __( 'Error Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.error' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'error_border_style',
			array(
				'label'     => __( 'Error Border Style', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'solid',
				'options'   => array(
					'none'   => __( 'None', 'smaily-wp-connect' ),
					'solid'  => __( 'Solid', 'smaily-wp-connect' ),
					'dashed' => __( 'Dashed', 'smaily-wp-connect' ),
					'dotted' => __( 'Dotted', 'smaily-wp-connect' ),
					'double' => __( 'Double', 'smaily-wp-connect' ),
					'groove' => __( 'Groove', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.error' => 'border-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'messages_error_border_color',
			array(
				'label'     => __( 'Error Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.error' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'error_border_style!' => 'none',
				),
				'default'   => '#b51b1b',
			)
		);

		$this->add_control(
			'messages_error_border_width',
			array(
				'label'      => __( 'Error Border Width', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.error' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'error_border_style!' => 'none',
				),
				'default'    => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 4,
					'unit'   => 'px',
				),
			)
		);

		$this->add_control(
			'messages_error_border_radius',
			array(
				'label'      => __( 'Error Border Radius', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.error' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'error_border_style!' => 'none',
				),
			)
		);

		$this->add_control(
			'messages_success_divider',
			array(
				'type' => \Elementor\Controls_Manager::DIVIDER,
			)
		);

		$this->add_control(
			'messages_success_background_color',
			array(
				'label'     => __( 'Success Background Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.success' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'success_border_style',
			array(
				'label'     => __( 'Success Border Style', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'solid',
				'options'   => array(
					'none'   => __( 'None', 'smaily-wp-connect' ),
					'solid'  => __( 'Solid', 'smaily-wp-connect' ),
					'dashed' => __( 'Dashed', 'smaily-wp-connect' ),
					'dotted' => __( 'Dotted', 'smaily-wp-connect' ),
					'double' => __( 'Double', 'smaily-wp-connect' ),
					'groove' => __( 'Groove', 'smaily-wp-connect' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.success' => 'border-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'messages_success_border_color',
			array(
				'label'     => __( 'Success Border Color', 'smaily-wp-connect' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.success' => 'border-color: {{VALUE}};',
				),
				'condition' => array(
					'success_border_style!' => 'none',
				),
				'default'   => '#1bb51b',
			)
		);

		$this->add_control(
			'messages_success_border_width',
			array(
				'label'      => __( 'Success Border Width', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.success' => 'border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'success_border_style!' => 'none',
				),
				'default'    => array(
					'top'    => 0,
					'right'  => 0,
					'bottom' => 0,
					'left'   => 4,
					'unit'   => 'px',
				),
			)
		);

		$this->add_control(
			'messages_success_border_radius',
			array(
				'label'      => __( 'Success Border Radius', 'smaily-wp-connect' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .smaily-wp-connect-elementor-newsletter-form-notice__content.success' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'  => array(
					'success_border_style!' => 'none',
				),
			)
		);

		$this->end_controls_section();
	}
}
