/** Map an action's logical icon key to a Font-Awesome class (Mantis ships FA4). */
export function actionIcon(icon: string): string {
  switch (icon) {
    case 'nextcloud':
      return 'fa-cloud';
    case 'editor':
      return 'fa-pencil-square-o';
    case 'open':
    default:
      return 'fa-external-link';
  }
}
