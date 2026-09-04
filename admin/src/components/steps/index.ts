/**
 * Step components barrel export.
 *
 * Both wizard (admin/wizard.php) and settings (admin/settings.php)
 * import these — same components, inSettings prop flips the chrome.
 */

export { Step1Connect, type Step1ConnectProps } from './Step1Connect';
export { Step2Subscribers, type Step2SubscribersProps } from './Step2Subscribers';
export { Step3WooCommerce, type Step3WooCommerceProps } from './Step3WooCommerce';
export {
  Step4Recommendations,
  type Step4RecommendationsProps,
} from './Step4Recommendations';
export { RssFeedSection, type RssFeedSectionProps } from './RssFeedSection';
export { Step5Integrations, type Step5IntegrationsProps } from './Step5Integrations';
export { Step6Done, type Step6DoneProps } from './Step6Done';
