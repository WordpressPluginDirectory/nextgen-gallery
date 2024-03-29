<tr id='tr_<?php print esc_attr( "{$display_type_name}_{$name}" ); ?>' class='<?php print ! empty( $hidden ) ? 'hidden' : ''; ?>'>
	<td>
		<label for="<?php print esc_attr( "{$display_type_name}_{$name}" ); ?>"
				<?php
				if ( ! empty( $text ) ) {
					?>
					title='<?php print esc_attr( $text ); ?>'<?php } ?>
				<?php
				if ( ! empty( $text ) ) {
					?>
					class='tooltip'<?php } ?>>
			<?php print wp_kses_post( $label ); ?>
		</label>
	</td>
	<td>
		<?php
		if ( is_array( $value ) ) {
			$value = empty( $value ) || ! is_string( reset( $value ) ) ? '' : reset( $value );
		}
		?>
		<input type='text'
				id='<?php print esc_attr( "{$display_type_name}_{$name}" ); ?>'
				name='<?php print esc_attr( "{$display_type_name}[{$name}]" ); ?>'
				class='<?php print esc_attr( "{$display_type_name}_{$name}" ); ?>'
				<?php
				if ( ! empty( $placeholder ) ) {
					?>
					placeholder='<?php print esc_attr( $placeholder ); ?>'<?php } ?>
				value='<?php print esc_attr( $value ); ?>'/>
	</td>
</tr>
