/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus'

/**
 * A house has no floor inside a building: when the picked property type is
 * "house", the floor block is hidden and its inputs disabled (so they are
 * neither submitted nor counted as required by the steps gate). Server-side,
 * the submission DTO applies the same rule.
 */
export default class extends Controller {
    static targets = ['type', 'wrapper', 'input']

    connect() {
        this.update()
    }

    update() {
        const checked = this.typeTargets.find((radio) => radio.checked)
        const isHouse = 'house' === checked?.value

        this.wrapperTarget.classList.toggle('hidden', isHouse)
        this.inputTargets.forEach((input) => {
            input.disabled = isHouse
        })
    }
}
